// ==UserScript==
// @name           Scraper
// @namespace      scraper
// @description    Allow a third-party website to drive the browser as a screen-scraper
// @include        $baseurl/*
{{ for $sites as $site }}// @include        $site.pattern
{{ endfor }}// ==/UserScript==

var friendica_baseurl = "$baseurl";
var friendica_user = "$nick";

function new_scraper(window, methods) {
var scraper = {
    server_url: friendica_baseurl,

    user: friendica_user,

    prompts: {},

    register_reply: function(response) {
        var reply = JSON.parse(response);
        if (reply["error"]) {
            this.log("Error during registration: " + reply["error"]);
            return;
        }
        if (reply["interval"]) {
            this.set_interval(reply);
        }
        if (reply["xslt"]) {
            this.set_xslt(reply);
        }
    },

    search_html: function(path) {
        var entries = document.evaluate(path, document, null, XPathResult.ANY_TYPE, null);
        return entries.iterateNext();
    },

    close_window: function() {
        this.window.close();
    },
    go_url: function(command) {
        this.window.location.href = command["url"];
    },
    get_url: function(command) {
        return this.window.location.href;
    },

    set_xslt: function(command) {
        this.request("GET", command["xslt"], null, "xslt_response");
    },
    get_scraped: function(command) {
        if (!this.scraped) {
            return;
        }
        var result = this.search_scraped(command["path"]);
        if (result) {
            return new XMLSerializer().serializeToString(result);
        }
    },

    alert: function(command) {
        return alert(command["message"]);
    },
    prompt: function(command) {
        return prompt(command["message"]);
    },
    confirm: function(command) {
        return confirm(command["message"]);
    },
    debug: function(command) {
        this.log(command["message"]);
    },

    enter_value: function(command) {
        this.search_html(command["path"]).value = command["value"];
    },
    enter_value_from_prompt: function(command) {
        if (command['id'] && this.prompts[command['id']]) {
            value = this.prompts[command['id']];
        }
        else {
            value = prompt(command["message"]);
            if (command['id'] && value) {
                this.prompts[command['id']] = value;
            }
        }
        if (value) {
            this.search_html(command["path"]).value = value;
            return 'ok';
        }
    },
    click: function(command) {
        this.search_html(command["path"]).click();
    },

    request_command: function(scraper, previous_result) {
        this.request("POST", scraper.server_url + "/scraper/command/" + scraper.user + "/" + scraper.id,
                     previous_result, "receive_command");
    },
    receive_command: function(response) {
        if (response) {
            var command = JSON.parse(response);
            if (command && command["function"]) {
                var result = this[command["function"]](command);
                this.request_command(this, result);
            }
        }
    },

    set_interval: function(command) {
        scraper = this;
        scraper.log("Setting interval to " + command["interval"]);
        if (command["interval"]) {
            this.interval = command["interval"];
            clearInterval(this.interval_id);
            this.interval_id = setInterval(function() {scraper.request_command(scraper);}, scraper.interval * 1000);
        }
    },
    sleep: function(command) {
        this.clearInterval(this.request_command_interval_id);
        setTimeout(this.wake, command["time"] * 1000);
    },
    wake: function() {
        clearInterval(this.request_command_interval_id);
        this.set_request_command_interval(this.request_command_interval);
    },
    terminate: function() {
        clearInterval(this.request_command_interval_id);
    },

    register: function() {
        this.request("POST", this.server_url + "/scraper/register/" + this.user + "/" + this.id,
                     this.window.location.href, "register_reply");
    },
};
    methods(scraper);

    scraper.id = Math.random().toString(16).substring(2,10);
    if (window) {
        scraper.window = window;
        if (window.name != "") {
            scraper.id = window.name;
        }
        else {
            window.name = scraper.id;
        }
    }
    scraper.log = scraper.window.console.log;

    return scraper;
}

var friendica_scraper;

function zombie_methods(scraper) {
    var xslt = require('node_xslt');

    scraper.request = function(method, url, data, callback) {
        var request = new friendica_scraper.window.XMLHttpRequest();
        var scraper = this;
        if (callback) {
            request.onreadystatechange = function() {
                if (request.status >= 200) {
                    request.onreadystatechange = function() {};
                    scraper[callback](request.responseText);
                }
            };
        };
        request.open(method, url);
        result = request.send(data);
    };

    scraper.open_window = function(command) {
        var Browser = require("zombie");
        if (Browser) {
            Browser.visit(command["url"], { debug: true },
                          function (e, browser) {
                              browser.window.name = "";
                              var scraper = new_scraper(browser.window, zombie_methods);
                              scraper.browser = browser;
                              process.argv.forEach(function (val, index, array) {
                                  if (pieces = val.match(/(\w+)=(.*)/)) {
                                      scraper.prompts[pieces[1]] = pieces[2];
                                  }
                              });
                              scraper.register();
                          });
        }
    };

    scraper.xslt_response = function(response) {
        this.xslt = xslt.readXsltString(response);
        this.scrape();
    };

    scraper.scrape = function() {
        var libxmljs = require("libxmljs");

        var newdoc = xslt.readHtmlString(this.browser.document.outerHTML);
        this.scraped = xslt.transform(this.xslt, newdoc, []);
        this.scraped = libxmljs.parseXmlString(this.scraped);
        var status = this.search_scraped("//sc:status");
        this.log('@@@ status is '  + status);
        this.request("POST", this.server_url + "/scraper/scraped/" + this.user + "/" + this.id, status.textContent, null);
    };

    scraper.search_scraped = function(path) {
        if (!this.scraped) {
            return;
        }

        var map = {};
        var gen_namespace_map = function(node) {
            if (node.namespace()) {
                map[node.namespace().prefix()] = node.namespace().href();
            }
            for (child in node.childNodes()) {
                gen_namespace_map(node.childNodes()[child]);
            }
        }
        gen_namespace_map(this.scraped.root());
        return this.scraped.find(path, map);
    };
}

function greasemonkey_methods(scraper) {
    scraper.request = function(method, url, data, callback) {
        GM_xmlhttpRequest({
            method: method,
            url: url,
            data: data,
            onload: function(x) { if (callback) { this.scraper[callback](x.responseText); } },
            scraper: this,
        });
    };

    scraper.open_window = function(command) {
        this.window.open(command["url"]);
    };

    scraper.xslt_response = function(response) {
        this.xslt = new XSLTProcessor();
        var xml = new DOMParser().parseFromString(response, "text/xml");
        this.xslt.importStylesheet(xml);
        this.scrape();
    };

    scraper.scrape = function() {
        if (this.xslt) {
            this.scraped = this.xslt.transformToDocument(document);
            var status = this.search_scraped("//sc:status");
            this.request("POST", this.server_url + "/scraper/scraped/" + this.user + "/" + this.id, status.textContent, null);
        }
    };

    scraper.search_scraped = function(path) {
        if (!this.scraped) {
            return;
        }
        // What the hell?  Serialize the XML, reparse it, and then do
        // the evaluate on that?  What's the point of all that?  I
        // have no idea, but doing it the sane way fails with
        // something about namespaces, and even though I don't
        // understand how or why, this fixes it.  If you've dealt with
        // XML namespaces before, you'll recognise the symptoms.
        // "LET'S ADD NAMESPACES TO JSON!  THEN IT'LL BE JUST AS GOOD
        // AS XML AND WE'LL BE INVITED TO THEIR PARTIES!!1!"

        var xmltext = new XMLSerializer().serializeToString(this.scraped);
        var xml = new DOMParser().parseFromString(xmltext, "text/xml");
        var resolver = xml.createNSResolver(xml);
        var entries = xml.evaluate(path, xml, resolver, XPathResult.ANY_TYPE, null);
        return entries.iterateNext();
    };
}

if (typeof require == 'function') {
    var Browser = require("zombie");
    if (Browser) {
        Browser.visit(friendica_baseurl, { debug: true },
                      function (e, browser) {
                          browser.window.name = "";
                          friendica_scraper = new_scraper(browser.window, zombie_methods);
                          friendica_scraper.register();
                      });
    }
}
else {
    var scraper = new_scraper(self.window, greasemonkey_methods);
    scraper.register();
}

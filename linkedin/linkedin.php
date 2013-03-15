<?php

/**
 * Name: LinkedIn
 * Description: Scraper Connector for LinkedIn
 * Version: 0.1
 * Author: Matthew Exon <http://mat.exon.name>
 */

function linkedin_install() {
    register_hook('scraper_site', 'addon/linkedin/linkedin.php', 'linkedin_site');
    register_hook('scraper_own', 'addon/linkedin/linkedin.php', 'linkedin_own');
}

function linkedin_uninstall() {
    unregister_hook('scraper_site', 'addon/linkedin/linkedin.php', 'linkedin_site');
    unregister_hook('scraper_own', 'addon/linkedin/linkedin.php', 'linkedin_own');
}

function linkedin_module() {}

function linkedin_site(&$a, &$sites) {
    $states = array('logout' => "Log out",
                    'scrape-home' => "Scrape Home Feed",
                    'login' => "Log in");
    $site = array('pattern' => "http://*linkedin.com/*",
                  'name' => 'linkedin',
                  'home' => 'http://linkedin.com',
                  'states' => $states);
    array_push($sites, $site);
}

function linkedin_own(&$a, &$window) {
    if (preg_match("/.*linkedin.com.*/", $window['url'])) {
        $window['network'] = "linkedin";
        $window['xslt'] = $a->get_baseurl() . '/linkedin/scrape.xml';
        $window['state'] = "wait";
    }
}

function linkedin_state_machine() {
    $baseurl = get_app()->get_baseurl();
    return array(
        'start' => function(&$window) {
            $window['state'] = 'wait';
            return array("function" => "set_xslt", "xslt" => $window['xslt']);
        },
        'wait' => function(&$window) {
            if (!$window['scraped']->page) {
            }
            if ($window['scraped']->page === 'unknown') {
                $window['state'] = 'extra-scrape';
                return null;
            }
            // By default, if we're on the home page, scrape it
            if ($window['scraped']->page === 'home') {
                $window['state'] = 'scrape-home';
            }
            return null;
        },
        'extra-scrape' => function(&$window) {
            $window['state'] = 'wait';
            return array("function" => "scrape");
        },
        'close' => function(&$window) {
            $window['state'] = 'wait';
            return array("function" => "close_window");
        },
        'login' => function(&$window) {
            $window['state'] = 'scrape-home';
            return null;
        },
        'enter-pass' => function(&$window) {
            $window['state'] = 'click-login';
            return array('function' => 'enter_value_from_prompt',
                         'id' => 'linkedin_password',
                         'path' => "//*[@id='session_password-login']",
                         'message' => "Please enter your LinkedIn password.  Might as well, everyone else has it.");
        },
        'click-login' => function(&$window) {
            $window['state'] = 'wait';
            return array('function' => 'click',
                         'path' => "//*[@id='btn-login']");
        },
        'scrape-home' => function(&$window) {
            if ($window['scraped']->page === 'lgin') {
                $window['state'] = 'enter-pass';
                return array("function" => "enter_value",
                             "path" => "//*[@id='session_key-login']",
                             "value" => get_pconfig($window['uid'], 'linkedin', 'user'));
            }
            if ($window['scraped']->page != 'home') {
                return array("function" => "go_url", "url" => "http://www.linkedin.com/");
            }
            if (isset($window['data']->fetch)) {
                $item = $window['data']->fetch;
                $results = file_get_contents("php://input");
                if ($results) {
                    if (gettype($window['data']->fetched) != 'object') {
                        $window['data']->fetched = new stdClass();
                    }
                    $window['data']->fetched->{$item} = 1;
                    unset ($window['data']->fetch);
                }
                else {
                    $path = "//li:post[li:update-date/text()='" . $item . "']";
                    return array("function" => "get_scraped", "path" => $path);
                }
            }
            if (!isset($window['data']->fetch)) {
                foreach ($window['scraped']->feeditems as $item) {
                    if ($item == "") {
                        continue;
                    }
                    if ((gettype($window['data']->fetched) != 'object') ||
                        !isset($window['data']->fetched->{$item})) {
                        $window['data']->fetch = $item;
                        $path = "//li:post[li:update-date/text()='" . $item . "']";
                        return array("function" => "get_scraped", "path" => $path);
                    }
                }
                // Nothing to ask for, let's see if there's anything else
                if (!isset($window['data']->lastscrape) || (time() - $window['data']->lastscrape > 60)) {
                    $window['data']->lastscrape = time();
                    return array("function" => "scrape");
                }
            }
        },
        'fetch-item' => function(&$window) {
            $item = $window['data']->fetch;
            $results = file_get_contents("php://input");
        }
        );
}

function linkedin_content(&$a) {
    if ($a->argv[1] === 'scrape.xml') {
        header("Content-type: application/xml");
        echo file_get_contents(dirname(__file__).'/scrape-linkedin.xml');
        killme();
    }
    $a->page['content'] .= "<p>Page not found</p>";
}

?>
<?php

include_once 'Mail/mimeDecode.php';

if (!class_exists('IncomingMail')) {

    class IncomingMail {
        public $raw_text;
        public $mime_structure;
        public $date;
        public $subject;
        public $body;
        public $body_type;
        public $cid;

        public static $have_mimeDecode = false;

        function __construct() {
            if (!IncomingMail::$have_mimeDecode) {
                logger("IncomingMail: couldn't load Mail/mimeDecode.php");
                return;
            }

            $this->raw_text = file_get_contents("php://stdin");

            $decoder = new Mail_mimeDecode($this->raw_text);
            $this->mime_structure = $decoder->decode(
                array('include_bodies' => TRUE,
                      'decode_bodies' => TRUE,
                      'decode_headers' => TRUE));
            $this->cid = array();
            $this->process_structure($this->mime_structure);

            logger('IncomingMail: got message from ' . $this->from . ' subject ' . $this->subject);
        }

        function process_structure($structure) {
            $type = $structure->ctype_primary . '/' . $structure->ctype_secondary;
            if ($structure->headers['message-id']) {
                $this->message_id = datetime_convert('UTC','UTC',$structure->headers['message-id']);
            }
            if ($structure->headers['date']) {
                $this->date = datetime_convert('UTC','UTC',$structure->headers['date']);
            }
            if ($structure->headers['subject']) {
                $this->subject = $structure->headers['subject'];
            }
            if ($structure->headers['from']) {
                $this->from = $structure->headers['from'];
            }
            if (($type == 'text/plain') && (!$self->body_type)) {
                $this->body = $structure->body;
                $this->body_type = $type;
            }
            // Match text/html and application/xhtml+xml, and hopefully nothing else
            if (preg_match("/html/", $type)) {
                if (!$self->body_type || (!preg_match("/html", $self->body_type))) {
                    $this->body = $structure->body;
                    $this->body_type = $type;
                }
            }
            if ($structure->ctype_primary === 'multipart') {
                foreach ($structure->parts as $part) {
                    $this->process_structure($part);
                }
            }
            if (preg_match('/<([^>]+)>/', $structure->headers['content-id'], $matches)) {
                $this->cid[$matches[1]] = $structure;
            }
        }

        /*
         * Searches for directives in the body of the mail, parses
         * them, removes them from the mail, and returns the results
         * as an array.  The idea is that plugins can each have their
         * own directives, for example [poke]1234[/poke] or [rename
         * contact_id=1234]Fred[/rename].  They can each call this
         * function during the incoming_mail hook.  Once all the
         * directives have been processed, the body left over will
         * just contain the bbcode or HTML content.
         *
         * The syntax is modelled after bbcode and probably best
         * explained with examples:
         *
         * "[poke]1234[/poke]" :
         * (
         *     [0] => Array
         *         (
         *             [value] => 1234
         *         )
         * )
         * 
         * "[rename contact_id=1234 public]Fred[/rename]" :
         * (
         *     [0] => Array
         *         (
         *             [value] => Fred
         *             [attributes] => Array
         *                 (
         *                     [contact_id] => 1234
         *                     [public] => 1
         *                 )
         *         )
         * )
         *
         * "[rename contact_id=1234 new=Fred]", true :
         * (
         *     [0] => Array
         *         (
         *             [attributes] => Array
         *                 (
         *                     [contact_id] => 1234
         *                     [new] => Fred
         *                 )
         *         )
         * )
         *
         * "[some_flag]", true :
         * (
         *     [0] => Array
         *         (
         *         )
         * )
         *
         * "[unfriend contact_id=1000 contact_id=1001 contact_id=1002][/unfriend]" :
         * (
         *     [0] => Array
         *         (
         *             [value] =>
         *             [attributes] => Array
         *                 (
         *                     [contact_id] => Array
         *                         (
         *                             [0] => 1000
         *                             [1] => 1001
         *                             [2] => 1002
         *                         )
         *                 )
         *         )
         * )
         */
        function consume_directive($name, $attribute_only = false) {
            //@@@ todo: look in the subject as well
            if ($attribute_only) {
                $pattern = "/\[$name( [^\]]+)?\]/ism";
            }
            else {
                $pattern = "/\[$name( [^\]]+)?\]([^\[]*)\[\/$name\]/ism";
            }
            $results = array();
            $num_directives = preg_match_all($pattern, $this->body, $directive_matches);
            for ($i = 0; $i < $num_directives; ++$i) {
                $attributes = array();
                $num_attributes = preg_match_all("/([A-Za-z0-9\-_]+)(=([^ ]+))?/ism",
                                                 $directive_matches[1][$i], $attribute_matches);
                for ($j = 0; $j < $num_attributes; ++$j) {
                    $value = $attribute_matches[2][$j] ? $attribute_matches[3][$j] : true;
                    if (array_key_exists($attribute_matches[1][$j], $attributes)) {
                        if (!is_array($attributes[$attribute_matches[1][$j]])) {
                            $attributes[$attribute_matches[1][$j]] = array($attributes[$attribute_matches[1][$j]]);
                        }
                        array_push($attributes[$attribute_matches[1][$j]], $value);
                    }
                    else {
                        $attributes[$attribute_matches[1][$j]] = $value;
                    }
                }
                $result = array();
                if (!$attribute_only) {
                    $result['value'] = $directive_matches[2][$i];
                }
                if (count($attributes)) {
                    $result['attributes'] = $attributes;
                }
                array_push($results, $result);
            }
            preg_replace($pattern, '', $this->body);
            logger('@@@ consume_directive ' . $name . ' results ' . print_r($results, true));
            return $results;
        }
    }

    IncomingMail::$have_mimeDecode = class_exists('Mail_mimeDecode');
}


?>

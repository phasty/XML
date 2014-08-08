<?php
namespace Phasty\XML {
    abstract class SAX {
        protected $env = [];
        protected $parser = null;

        protected function openTag($parser, $nodeName, $attrs) {
            $this->env []= strtolower($nodeName);
            if (is_callable([$this, $callback = "openTag_" . implode("_", $this->env)])) {
                $this->$callback($attrs);
            }
        }

        protected function closeTag($parser, $nodeName) {
            if (is_callable([$this, $callback = "closeTag_" . implode("_", $this->env)])) {
                $this->$callback();
            }
            array_pop($this->env);
        }

        public function parse($file) {
            try {
                $autoclose = false;
                if (!is_resource($file)) {
                    if (!is_file($file)) {
                        throw new \Exception();
                    }
                    if (!($file = fopen($file, "r"))) {
                        throw new \Exception("Could not open file");
                    }
                    $autoclose = true;
                }

                $this->parser = xml_parser_create();
                xml_parser_set_option($this->parser, XML_OPTION_CASE_FOLDING, false);
                xml_set_element_handler($this->parser, [$this, "openTag"], [$this, "closeTag"]);

                while ($data = fread($file, 4096)) {
                    if (!xml_parse($this->parser, $data, feof($file))) {
                        throw new \Exception(sprintf("XML error: %s at line %d",
                                    xml_error_string(xml_get_error_code($this->parser)),
                                    xml_get_current_line_number($this->parser)));
                    }
                }
            } catch (\Exception $e) {
                $this->free();
                throw $e;
            }
            if ($autoclose) fclose ($file);
            $this->free();
        }

        public function free() {
            xml_parser_free($this->parser);
            $this->parser = null;
        }
    }
}

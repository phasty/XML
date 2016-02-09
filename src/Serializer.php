<?php
namespace Phasty\XML {
    class ClassNotFoundException extends \Exception {}

    /**
     * Serializes/unserializes to/from xml
     */
    class Serializer {
        protected $config = [
            "extractClassFrom" => "tagName",
            "classesNamespace" => "\\",
            "skipUnknownObjects" => true,
            "mapperClasses" => []
        ];

        /**
         * Unserialize xml string
         *
         * @param string $xml xml string
         *
         * @return mixed unserialized object
         */
        public function unserialize($xml) {
            return $this->unserializeXml(new \SimpleXMLElement($xml));
        }

        /**
         * Unserialize SimpleXMLElement
         *
         * @param \SimpleXMLElement $element   Element to unserialize
         * @param string            $classHint Hint to which class unserialize
         *
         * @return mixed unserialized object
         */
        protected function unserializeXml(\SimpleXMLElement $element, $classHint = null) {
            if ($this->configValue("extractClassFrom", "tagName") == "tagName") {
                $elementClassHint = $element->getName();
            } else {
                $xsiAttrs = $element->attributes("http://www.w3.org/2001/XMLSchema-instance");
                if (!isset($xsiAttrs["type"])) {
                    throw new \Exception("Element {$element->getName()} has no {http://www.w3.org/2001/XMLSchema-instance}type attribute");
                }
                $elementClassHint = $xsiAttrs["type"];
            }
            $mapperClass = $this->configValue("mapperClasses", []);
            if (isset($mapperClass[$elementClassHint])) {
                $className = $mapperClass[$elementClassHint];
            } else {
                $className = $classHint ? $classHint : rtrim($this->configValue("classesNamespace", ""), '\\') . '\\' . $elementClassHint;
            }
            if (!class_exists($className, true)) {
                throw new ClassNotFoundException("Class '$className' not found");
            }
            $classInstance = new $className;
            $this->checkNodes($element->attributes(), $className, $classInstance);
            $this->checkNodes($element->children(),   $className, $classInstance);
            return $classInstance;
        }

        /**
         * Iterate over xml nodes (attributes or elements) on \SimpleXMLElement
         *
         * @param \SimpleXMLElement $node          Iterable object
         * @param string            $className     Class name to which unserialize object
         * @param mixed             $classInstance Class instance to populate with properties
         */
        protected function checkNodes(\SimpleXMLElement $node, $className, $classInstance) {
            foreach ($node as $child) {
                $propertyName = $child->getName();
                $setter = "set" . ucfirst($propertyName);
                if (!method_exists($classInstance, $setter) && $this->configValue("skipUnknownObjects")) {
                    continue;
                }
                $methRef = new \ReflectionMethod($className, $setter);
                $hintType = $methRef->getParameters()[0]->getClass();
                if ($hintType) {
                    $propertyValue = $this->unserializeXml($child, $hintType->name);
                    $classInstance->$setter($propertyValue);
                } else {
                    $classInstance->$setter((string)$child);
                }
            }
        }

        /**
         * Get config value
         *
         * @param string $key     Config name
         * @param mixed  $default Default config value
         *
         * @return mixed Config value or $default
         */
        protected function configValue($key, $default = null) {
            return isset($this->config[ $key ]) ? $this->config[ $key ] : $default;
        }

        /**
         * Replace default config
         *
         * @param array $config
         */
        public function config(array $config) {
            $this->config = array_replace($this->config, $config);
        }

        /**
         * Serialize object to xml
         *
         * @param mixed  $object      Object to serialize
         * @param string $elementName Element name serialize to. If not specified, taken from annotation or class name
         * @param mixed  $parent      \SimpleXMLElement parent element or null if no parent
         */
        public function serialize($object, $elementName = null, $parent = null) {
            $classFullName = get_class($object);
            $classRef = new \ReflectionClass($classFullName);
            $classAnnot = $this->getAnnotation($classRef);
            $className = substr($classFullName, strripos($classFullName, "\\") + 1);
            $elementName = $elementName ? $elementName : (isset($classAnnot->name) ? $classAnnot->name : $className);

            if ($parent instanceof \SimpleXMLElement) {
                $xmlElement = $parent->addChild($elementName);
            } else {
                $xmlElement = new \SimpleXmlElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><$elementName />");
            }
            $this->serializeProperties($object, $classRef, $xmlElement);
            return $xmlElement->asXML();
        }

        /**
         * Add all serializable properties to xml element
         *
         * @param mixed             $object     Object where properties should be taken from
         * @param \ReflectionClass  $classRef   Reflection instance for $object
         * @param \SimpleXMLElement $xmlElement Element to populate in
         */
        protected function serializeProperties($object, $classRef, $xmlElement) {
            foreach ($classRef->getProperties() as $property) {
                $annot = $this->getAnnotation($property);
                // this property should not be serialized
                if ($annot === false) {
                    continue;
                }
                // match getter method for property
                if (isset($annot->getter)) {
                    $getter = $annot->getter;
                } else {
                    if (!method_exists($object, $getter = "get" . ucfirst($property->getName()))) {
                        continue;
                    }
                }
                $values = $object->$getter();
                // Child element (attribute) name is taken from property name by default.
                // Take name from annotation if has such
                $childName = isset($annot->nameFrom) && $annot->nameFrom === "child" ? null : $property->getName();
                // Scalar values may be serialized in properties. Look as annotation
                if (isset($annot->as) && $annot->as === "attr") {
                    if (is_null($values)) {
                        continue;
                    }
                    if (is_object($values)) {
                        if (is_callable([ $values, "__toString" ])) {
                            $values = "$values";
                        } else {
                            // TODO: throw appropriate exception
                            throw new \Exception("Object of class " . get_class($values) . " cannot be serialized as simple type");
                        }
                    }
                    if (is_array($values)) {
                        $values = implode(" ", $values);
                    }
                    $xmlElement->addAttribute($childName, $values);
                    continue;
                }
                // Cannot use (array) wrapping due to object to array conversion
                $values = is_array($values) ? $values : [ $values ];
                foreach ($values as $value) {
                    if (is_null($value) && empty($annot->nil)) {
                        continue;
                    }
                    // Serialize scalar values directly
                    if (is_scalar($value)) {
                        $xmlElement->addChild($childName, $value);
                    } else {
                        $this->serialize($value, $childName, $xmlElement);
                    }
                }
            }
        }

        /**
         * Get class or property annotation
         *
         * @param mixed $object \ReflectionClass or \ReflectionMethod
         *
         * @return mixed false if no annotation or array describing annotation
         */
        protected function getAnnotation($object) {
            $comment = $object->getDocComment();
            if (!preg_match("#@xml(?:\((.*)\))?#m", $comment, $matches)) {
                return false;
            }
            return isset($matches[1]) ? json_decode("{".$matches[1]."}") : [];
        }
    }
}


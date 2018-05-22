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
        
        protected $classSummaries = [];

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
            $classSummary  = $this->getClassSummary($className);
            $attrProps     = [];
            $childProps    = [];
            foreach ($classSummary["properties"] as $propertyName => $propertyAnnot) {
                if (isset($propertyAnnot->as) && $propertyAnnot->as == "attr") {
                    $attrProps[$propertyName]  = $propertyAnnot;
                } else {
                    $childProps[$propertyName] = $propertyAnnot;
                }
            }
            $this->checkNodes($element->attributes(), $className, $classInstance, $classSummary["class"], $attrProps);
            $this->checkNodes($element->children(),   $className, $classInstance, $classSummary["class"], $childProps);
            return $classInstance;
        }

        /**
         * Iterate over xml nodes (attributes or elements) on \SimpleXMLElement
         *
         * @param \SimpleXMLElement $node            Iterable object
         * @param string            $className       Class name to which unserialize object
         * @param mixed             $classInstance   Class instance to populate with properties
         * @param \stdClass         $classAnnotation Class annotation
         * @param array             $propAnnotations Annotations of properties to process
         *
         * @throws \Exception
         */
        protected function checkNodes(
            \SimpleXMLElement $node,
            $className,
            $classInstance,
            $classAnnotation,
            array $propAnnotations
        ) {
            $propIndex = [];
            foreach ($propAnnotations as $propName => $propAnnot) {
                $nodeName = isset($propAnnot->name) ? $propAnnot->name : $propName;
                $propIndex[$nodeName] = $propName;
            }
            foreach ($node as $child) {
                $nodeName = $child->getName();
                $setter   = null;
                $value    = null;
                if (!empty($propIndex[$nodeName])) {
                    $propertyName = $propIndex[$nodeName];
                    $setter       = "set" . ucfirst($propertyName);
                    if (method_exists($classInstance, $setter)) {
                        $methodRef = new \ReflectionMethod($className, $setter);
                        $hintType  = $methodRef->getParameters()[0]->getClass();
                        $value     = ($hintType) ? $this->unserializeXml($child, $hintType->name) : (string) $child;
                    } else {
                        throw new \Exception("Method " . $setter . " not found in class " . $className);
                    }
                } elseif (isset($classAnnotation->defaultSetter))  {
                    $setter = $classAnnotation->defaultSetter;
                    $value  = $this->unserializeXml($child);
                } elseif ($this->configValue("skipUnknownObjects")) {
                    continue;
                } else {
                    throw new \Exception("Method for setting " . $nodeName . " not found in class " . $className);
                }
                $classInstance->$setter($value);
            }
        }
        
        /**
         * Get class and its properties annotation from cache
         * 
         * @param string $className Class name
         * 
         * @return array Class summary
         */
        protected function getClassSummary($className) {
            if (!isset($this->classSummaries[$className])) {
                $classRef = new \ReflectionClass($className);
                $classSummary["class"]      = $this->getAnnotation($classRef);
                $classSummary["properties"] = [];
                foreach ($classRef->getProperties() as $propertyRef) {
                    $propAnnotation = $this->getAnnotation($propertyRef);
                    if ($propAnnotation !== false) {
                        $classSummary["properties"][$propertyRef->getName()] = $propAnnotation;
                    }
                }
                $this->classSummaries[$className] = $classSummary;
            }
            return $this->classSummaries[$className];
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
            $classAnnotation = $this->getAnnotation($classRef);
            $skipWhenEmpty = isset($classAnnotation->skipWhenEmpty) ? $classAnnotation->skipWhenEmpty : true;
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
                    } elseif (is_array($values)) {
                        $values = implode(" ", $values);
                    }
                    if (isset($annot->name)) {
                        $childName = $annot->name;
                    }
                    $xmlElement->addAttribute($childName, $this->sanitizeValue($values, $annot));
                    continue;
                }
                // Cannot use (array) wrapping due to object to array conversion
                $values = is_array($values) ? $values : [ $values ];
                foreach ($values as $value) {
                    if (is_null($value)) {
                        if (isset($annot->nil) ? !$annot->nil : $skipWhenEmpty) {
                            continue;
                        } else {
                            $xmlElement->addChild($childName)->addAttribute(
                                "xsi:nil",
                                "true",
                                "http://www.w3.org/2001/XMLSchema-instance"
                            );
                        }
                    } elseif (is_scalar($value)) {
                        // Serialize scalar values directly
                        $xmlElement->addChild($childName, $this->sanitizeValue($value, $annot));
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

        /**
         * Sanitize value
         *
         * @param string $value value
         * @param array $annot annotation
         *
         * @return string sanitized value
         */
        protected function sanitizeValue($value, $annot) {
            if (isset($annot->maxLength)) {
                $maxLength = (int) $annot->maxLength;
                if ($maxLength <= 0) {
                    throw new \Exception("Incorrect maxLength value: " . $maxLength);
                }
                $value = mb_substr($value, 0, $maxLength);
            }

            return $value;
        }
    }
}


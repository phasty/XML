<?php
namespace Phasty\XML {
    class ClassNotFoundException extends \Exception {}

    /**
     * Serializes/unserializes to/from xml
     */
    class Serializer {
        const ONLY_ELEMENTS = 0;
        const ONLY_ATTRIBUTES = 1;

        protected $config = [
            "extractClassFrom" => "tagName",
            "classesNamespace" => "\\",
            "skipUnknownObjects" => true,
            "mapperClasses" => []
        ];

        protected $classesAnnotation = [];

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
            $this->unserializeChildAttributes($element, $classInstance);
            $this->unserializeChildElements($element, $classInstance);
            return $classInstance;
        }

        /**
         * Unserialize child elements of node
         *
         * @param \SimpleXMLElement $element       Parent element
         * @param mixed             $classInstance Instance where values to store
         */
        protected function unserializeChildElements($element, $classInstance) {
            $this->checkNodes($element->children(), $classInstance, self::ONLY_ELEMENTS);
        }

        /**
         * Unserialize attributes of node
         *
         * @param \SimpleXMLElement $element       Parent element
         * @param mixed             $classInstance Instance where values to store
         */
        protected function unserializeChildAttributes($element, $classInstance) {
            $this->checkNodes($element->attributes(), $classInstance, self::ONLY_ATTRIBUTES);
        }

        /**
         * Find property which should be populated from xml property
         *
         * @param string  $class        Class name, where property should be found
         * @param string  $xmlProperty  Name of xml property
         * @param integer $onlyElOrAttr One of self::ONLY_ELEMENTS, self::ONLY_ATTRIBUTES
         */
        protected function findClassPropertyByXMLProperty($class, $xmlProperty, $onlyElOrAttr) {
            $ref = new \ReflectionClass($class);
            $qualifiedXmlName = $this->toQualifiedName($xmlProperty);
            foreach ($ref->getProperties() as $property) {
                if (false === ($propAnnot = $this->getAnnotation($property))) {
                    continue;
                }
                if ($onlyElOrAttr == self::ONLY_ATTRIBUTES && (empty($propAnnot->as) || $propAnnot->as !== "attr") ||
                    $onlyElOrAttr == self::ONLY_ELEMENTS && !empty($propAnnot->as) && $propAnnot->as === "attr") {
                    continue;
                }
                if (empty($propAnnot->name) && ucfirst($property->getName()) == $qualifiedXmlName) {
                    return $property->getName();
                }
                if (isset($propAnnot->name) && $propAnnot->name == $xmlProperty) {
                    return $property->getName();
                }
            }
            return false;
        }

        /**
         * Finds setter in class by xml property name
         *
         * @param string $class       Class name where to find setter
         * @param string $xmlProperty Xml property name
         * @param integer $onlyElOrAttr One of self::ONLY_ELEMENTS, self::ONLY_ATTRIBUTES
         */
        protected function getPropertySetter($class, $xmlProperty, $onlyElOrAttr) {
            // Ищем свойство, которое конвертится в этот xml элемент
            if ($classProp = $this->findClassPropertyByXMLProperty($class, $xmlProperty, $onlyElOrAttr)) {
                $setter = "set" . $this->toQualifiedName($classProp);
                if (method_exists($class, $setter)) {
                    return $setter;
                    $methRef = new \ReflectionMethod($class, $setter);
                    $hintType = $methRef->getParameters()[0]->getClass();
                    return $hintType ? $this->unserializeXml($child, $hintType->name) : (string) $child;
                }
            }

            $classAnnot = $this->getClassAnnotation($class);
            if (isset($classAnnot->defaultSetter)) {
                return $classAnnot->defaultSetter;
                $propertyValue = $this->unserializeXml($child);
            } elseif ($this->configValue("skipUnknownObjects")) {
                return false;
            } else {
                $type = $onlyElOrAttr == self::ONLY_ELEMENTS ? "element" : "attribute";
                throw new \Exception("Setter for $type $xmlProperty not found in class " . $class);
            }
        }

        /**
         * Normalizes xml name
         *
         * @param string $name XML name
         *
         * @return string Normalized name
         */
        protected function toQualifiedName($name) {
            return str_replace('-', '', ucwords($name));
        }

        /**
         * Iterate over xml nodes (attributes or elements) on \SimpleXMLElement
         *
         * @param \SimpleXMLElement $node          Iterable object
         * @param string            $className     Class name to which unserialize object
         * @param mixed             $classInstance Class instance to populate with properties
         */
        protected function checkNodes(\SimpleXMLElement $node, $classInstance, $onlyElOrAttr) {
            $class = get_class($classInstance);
            foreach ($node as $child) {
                $propertyName = $child->getName();
                $setter = $this->getPropertySetter($class, $propertyName, $onlyElOrAttr);
                if (!$setter && !$this->configValue("skipUnknownObjects")) {
                    throw new \Exception("Setter " . $setter . " not found in class " . $class);
                }
                $methRef = new \ReflectionMethod($class, $setter);
                $hintType = $methRef->getParameters()[0]->getClass();
                $propertyValue = $hintType ? $this->unserializeXml($child, $hintType->name) : (string) $child;
                $classInstance->$setter($propertyValue);
            }
        }

        /**
         * Get class annotation from cache
         *
         * @param string $className Class name
         *
         * @return mixed false if no annotation or array describing annotation
         */
        protected function getClassAnnotation($className) {
            if (!isset($this->classesAnnotation[$className])) {
                $this->classesAnnotation[$className] = $this->getAnnotation(new \ReflectionClass($className));
            }
            return $this->classesAnnotation[$className];
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
            $className = substr($classFullName, strripos($classFullName, "\\"));
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
                if (isset($annot->nameFrom) && $annot->nameFrom === "child") {
                    $childName = null;
                } elseif (isset($annot->name)) {
                    $childName = $annot->name;
                } else {
                    $childName = $property->getName();
                }
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
                    if (is_null($value) && empty($annot->nil)) {
                        continue;
                    }
                    // Serialize scalar values directly
                    if (is_scalar($value)) {
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


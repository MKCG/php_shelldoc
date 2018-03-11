<?php

namespace MKCG\Shelldoc;

class PhpDomParser
{
    public function parseDocumentation(string $function, string $html) : array
    {
        $dom = new \DOMDocument();
        $dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $alias = str_replace('_', '-', $function);

        $phpDoc = [
            'name'      => $function,
            'parameters' => $this->parseParameters($alias, $xpath),
            'return' => [
                'type' => $this->parseReturnType($alias, $xpath),
                'values' => $this->parseReturnValues($alias, $xpath),
            ],
            'short' => $this->parseShortDescription($alias, $xpath),
            'examples'  => $this->parseExamples($alias, $xpath),
        ];

        return $phpDoc;
    }

    private function parseShortDescription(string $function, \DOMXPath $xpath) : string
    {
        $nodes = $xpath->query('//*[@id="function.' . $function . '"]/div[@class="refnamediv"]/p[@class="refpurpose"]/span[@class="dc-title"]');

        foreach ($nodes as $node) {
            return $node->textContent;
        }

        return '';
    }

    private function parseReturnType(string $function, \DOMXPath $xpath) : string
    {
        $returnType = $xpath->query('//*[@id="refsect1-function.' . $function . '-description"]/div/span[@class="type"]');

        foreach ($returnType as $type) {
            return $type->textContent;
        }

        return '';
    }

    private function parseParameters(string $function, \DOMXPath $xpath) : array
    {
        $parameters = [];

        $nodes = $xpath->query('//*[@id="refsect1-function.' . $function . '-description"]/div/span[@class="methodparam"]');

        foreach ($nodes as $node) {
            $parameter = [];

            foreach ($node->childNodes as $child) {
                foreach ($child->attributes as $attribute) {
                    if($attribute->nodeName === 'class') {
                        switch ($attribute->textContent) {
                            case 'parameter':
                                $parameter['name'] = $child->textContent;
                                break;
                            case 'type':
                                $parameter['type'] = $child->textContent;
                                break;
                            case 'initializer':
                                $defaultValue = $child->textContent;
                                $defaultValue = substr($defaultValue, strpos($defaultValue, '=') + 1);
                                $parameter['default_value'] = trim($defaultValue);
                                break;
                        }
                    }
                }
            }

            if (isset($parameter['name'])) {
                $parameters[substr($parameter['name'], 1)] = $parameter;
            }
        }

        if (empty($parameters)) {
            return [];
        }

        $nodes = $xpath->query('//*[@id="refsect1-function.' . $function . '-parameters"]/dl');

        foreach ($nodes as $node) {
            $currentParameter = '';

            foreach ($node->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }

                switch ($child->tagName) {
                    case 'dt':
                        $parameter = trim($child->textContent);

                        if (isset($parameters[$parameter])) {
                            $currentParameter = $parameter;
                        }
                        break;
                    case 'dd':
                        if ($currentParameter !== '') {
                            $descNode = simplexml_import_dom($child);
                            $description = [];

                            foreach ($descNode->children() as $text) {
                                $text = $text->asXML();
                                $text = $this->cleanText($text);
                                $text = explode("\n", $text);
                                $text = array_map('trim', $text);
                                $text = array_reduce($text, function ($acc, $line) {
                                    if ($acc === '') {
                                        return $line;
                                    }

                                    return $line === ''
                                        ? $acc . "\n"
                                        : $acc . " " . $line;
                                }, '');

                                $description[] = $text;
                            }

                            $parameters[$currentParameter]['description'] = implode("\n\n", $description);
                        }

                        $currentParameter = '';
                        break;
                    default:
                        $currentParameter = '';
                        break;
                }
            }
            break;
        }

        return array_values($parameters);
    }

    private function parseReturnValues(string $function, \DOMXPath $xpath) : array
    {
        $values = [];
        $returnedValues = $xpath->query('//*[@id="refsect1-function.' . $function . '-returnvalues"]/p/strong/code');

        foreach ($returnedValues as $returnValue) {
            $values[] = trim(strip_tags($returnValue->textContent));
        }

        $values = array_unique($values);

        return $values;
    }

    private function parseExamples(string $function, \DOMXPath $xpath) : array
    {
        $examples = [];
        $nodes = $xpath->query('//*[@id="refsect1-function.' . $function . '-examples"]/div[@class="example"]');

        foreach ($nodes as $node) {
            $currentExample = [];
            $dom = simplexml_import_dom($node);

            foreach ($dom->children() as $child) {
                $text = $child->asXML();

                if (!isset($currentExample['title'])) {
                    $text = strip_tags($text);
                    $currentExample['title'] = $text;
                } else {
                    $text = $this->cleanText($text);
                    $currentExample['content'] = $text;
                    $examples[] = $currentExample;
                    break;
                }
            }
        }

        return $examples;
    }

    private function cleanText(string $text) : string
    {
        $text = str_replace(['<br/>', '<br>', '<br />', '<br >', '<br/ >'], "\n", $text);
        $text = strip_tags($text);
        $text = htmlspecialchars_decode($text);
        $text = trim($text);

        return $text;
    }
}

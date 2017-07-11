<?php
/**
 * User: lincanbin
 * Date: 2017/6/9
 * Time: 11:35
 */

namespace lincanbin;

use \DOMDocument;
use \DOMElement;
use \DOMAttr;
use \Exception;


/**
 * @property WhiteHTMLFilterConfig config
 * @property DOMDocument dom
 */
class WhiteHTMLFilter
{
    public $config;
    private $dom = NULL;

    public function __construct()
    {
        if (extension_loaded("dom") === false) {
            throw new Exception('DOM extension is required. http://php.net/manual/en/dom.installation.php');
        }
        $this->config = new WhiteHTMLFilterConfig();

        if (!$this->dom) {
            $this->dom = new DOMDocument();
        }
        $this->dom->preserveWhiteSpace = true;
        $this->dom->formatOutput = false;
        $this->dom->encoding = 'UTF-8';
        //Disable libxml errors
        libxml_use_internal_errors(true);
    }

    /**
     * Get current tag whitelist
     * @return array
     */
    public function getWhiteListTags()
    {
        return ($this->config->WhiteListTag);
    }

    /**
     * Load document markup into the class for cleaning
     * @param string $html The markup to clean
     * @return bool
     */
    public function loadHTML($html)
    {
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', "UTF-8");
        $html = str_replace(chr(13), '', $html);
        if (version_compare(PHP_VERSION, '5.4.0') < 0) {
            return $this->dom->loadHTML($html);
        } else {
            return $this->dom->loadHTML($html, LIBXML_HTML_NODEFDTD);
        }

    }

    /**
     * Output the result
     * @return string HTML string
     */
    public function outputHtml()
    {
        $result = '';
        if (!is_null($this->dom)) {
            $GenerateTag = function ($tagName) {
                return '<' . $tagName . '>';
            };
            $allowTagsString = implode('', array_map($GenerateTag, array_keys($this->config->WhiteListTag)));
            //SaveXML : <br/><img/>
            //SaveHTML: <br><img>
            $result = trim($this->dom->saveXML());
            $result = mb_convert_encoding($result, "UTF-8", 'HTML-ENTITIES');
            $result = strip_tags($result, $allowTagsString);
        }
        return $result;
    }

    /**
     * Clean the attributes of the html tags
     * @param DOMElement $elem
     */
    private function cleanAttributes(DOMElement $elem)
    {
        $tagName = strtolower($elem->nodeName);
        $attributes = $elem->attributes;
        $index = $attributes->length;
        $attributesWhiteList = array_merge($this->config->getWhiteListAttr($tagName), $this->config->WhiteListHtmlGlobalAttributes);
        $allowDataAttribute = in_array("data-*", $attributesWhiteList);
        while (--$index >= 0) {
            /* @var $domAttr DOMAttr */
            $domAttr = $attributes->item($index);
            $attrName = strtolower($domAttr->name);
            // 如果不在白名单attr中，而且允许data-*，且不是data-*，则删除
            if (!in_array($attrName, $attributesWhiteList) && $allowDataAttribute && (stripos($attrName, "data-") !== 0)) {
                $elem->removeAttribute($attrName);
            } else {
                $this->cleanAttrValue($domAttr);
            }
        }
    }

    /**
     * Clean the value of the attribute
     * @param DOMAttr $domAttr
     */
    private function cleanAttrValue(DOMAttr $domAttr)
    {
        $attrName = strtolower($domAttr->name);
        if ($attrName === 'style' && !empty($this->config->WhiteListStyle)) {
            $styles = explode(';', $domAttr->value);
            foreach ($styles as $key => &$subStyle) {
                $subStyle = array_map("trim", explode(':', strtolower($subStyle), 2));
                if (empty($subStyle[0]) || !in_array($subStyle[0], $this->config->WhiteListStyle)) {
                    unset($styles[$key]);
                }
            }
            $implodeFunc = function ($styleSheet) {
                return implode(':', $styleSheet);
            };
            $domAttr->value = implode(';', array_map($implodeFunc, $styles)) . ';';

        }
        if ($attrName === 'class' && !empty($this->config->WhiteListCssClass)) {
            $domAttr->value = implode(' ', array_intersect(preg_split('/\s+/', $domAttr->value), $this->config->WhiteListCssClass));
        }
        if ($attrName === 'src' || $attrName === 'href') {
            if (strtolower(parse_url($domAttr->value, PHP_URL_SCHEME)) === 'javascript') {
                $domAttr->value = '';
            } else {
                $domAttr->value = filter_var($domAttr->value, FILTER_SANITIZE_URL);
            }
        }
    }

    /**
     * Check if there is a valid text in the tag
     * @param string $string
     * @return boolean Whether there is valid text
     */
    private function isValidText($string)
    {
        $search = array(" ", "　", "\n", "\r", "\t");
        $replace = array("", "", "", "", "");
        /*
         * http://php.net/manual/en/function.empty.php
         * Prior to PHP 5.5, empty() only supports variables;
         * anything else will result in a parse error.
         * In other words, the following will not work: empty(trim($name)). Instead, use trim($name) == false.
         * */
        $temp = str_replace($search, $replace, $string);
        return (empty($temp) === false);
    }

    /**
     * Recursivly remove elements from the DOM that aren't whitelisted
     * @param DOMElement $elem
     * @param boolean $isFirstNode
     * @return array List of elements removed from the DOM
     * @throws Exception If removal of a node failed than an exception is thrown
     */
    private function cleanNodes(DOMElement $elem, $isFirstNode = false)
    {
        $removed = array();
        if ($isFirstNode || array_key_exists(strtolower($elem->nodeName), $this->config->WhiteListTag)) {
            if ($elem->hasAttributes()) {
                $this->cleanAttributes($elem);
            }
            /*
             * Iterate over the element's children. The reason we go backwards is because
             * going forwards will cause indexes to change when elements get removed
             */
            if ($elem->hasChildNodes()) {
                $children = $elem->childNodes;
                $index = $children->length;
                while (--$index >= 0) {
                    $cleanNode = $children->item($index);// DOMElement or DOMText
                    if ($cleanNode instanceof DOMElement) {
                        $removed = array_merge($removed, $this->cleanNodes($cleanNode));
                    }
                }
            }
        } else {
            $textContent = $elem->textContent;
            if ($this->isValidText($textContent)) {
                $result = $elem->parentNode->replaceChild($this->dom->createTextNode($textContent), $elem);
            } else {
                $result = $elem->parentNode->removeChild($elem);
            }
            if ($result) {
                $removed[] = $elem;
            } else {
                throw new Exception('Failed to remove node from DOM');
            }
        }
        return ($removed);
    }

    /**
     * Perform the cleaning of the document
     * @return array List of deleted HTML tags
     */
    public function clean()
    {
        return $this->cleanNodes($this->dom->getElementsByTagName('body')->item(0), true);
    }
}
<?php

require_once 'PHPTAL/Php/Attribute.php';

// i18n:attributes
//
// This attribute will allow us to translate attributes of HTML tags, such
// as the alt attribute in the img tag. The i18n:attributes attribute
// specifies a list of attributes to be translated with optional message
// IDs? for each; if multiple attribute names are given, they must be
// separated by semi-colons. Message IDs? used in this context must not
// include whitespace.
//
// Note that the value of the particular attributes come either from the
// HTML attribute value itself or from the data inserted by tal:attributes.
//
// If an attibute is to be both computed using tal:attributes and translated,
// the translation service is passed the result of the TALES expression for
// that attribute.
//
// An example:
//
//     <img src="http://foo.com/logo" alt="Visit us"
//              tal:attributes="alt here/greeting"
//              i18n:attributes="alt"
//              />
//
//
// In this example, let tal:attributes set the value of the alt attribute to
// the text "Stop by for a visit!". This text will be passed to the
// translation service, which uses the result of language negotiation to
// translate "Stop by for a visit!" into the requested language. The example
// text in the template, "Visit us", will simply be discarded.
//
// Another example, with explicit message IDs:
//
//   <img src="../icons/uparrow.png" alt="Up"
//        i18n:attributes="src up-arrow-icon; alt up-arrow-alttext"
//   >
//
// Here, the message ID up-arrow-icon will be used to generate the link to
// an icon image file, and the message ID up-arrow-alttext will be used for
// the "alt" text.
//

/**
 * @package phptal.php.attribute
 */
class PHPTAL_Php_Attribute_I18N_Attributes extends PHPTAL_Php_Attribute
{
    public function start()
    {
        /**
         * -------
         * C4 hack
         * -------
         *
         * Depending on the template's context, localization keys could come
         * into an XML encoded form (for example, "&amp;modules.section.localkey;"
         * instead of "&modules.section.localkey;").
         *
         * @author INTcourS
         * @date 2007-02-21
         * @since 2.0
         */
        $this->expression = str_replace('&amp;', '&', $this->expression);
        // split attributes to translate
        $expressions = $this->tag->generator->splitExpression($this->expression);
        // foreach attribute
        foreach ($expressions as $exp){
            list($attribute, $key) = $this->parseSetExpression($exp);
            /**
             * -------
             * C4 hack
             * -------
             *
             * Please don't break on C4's odd cases.
             *
             * @author INTcourS
             * @date 2007-02-21
             * @since 2.0
             */
            if (empty($attribute) && empty($key)) continue;
            //   if the translation key is specified
            if ($key != null){
                /**
                 * -------
                 * C4 hack
                 * -------
                 *
                 * Restore the stripped ';' at the end of the locale key.
                 *
                 * @author INTcourS
                 * @date 2007-02-21
                 * @since 2.0
                 */
                $key .= ';';
                // we use it and replace the tag attribute with the result of
                // the translation
                $key = str_replace('\'', '\\\'', $key);
                $this->tag->attributes[$attribute] = $this->_getTranslationCode("'$key'");
            }
            else if ($this->tag->isOverwrittenAttribute($attribute)){
                $varn = $this->tag->getOverwrittenAttributeVarName($attribute);
                $this->tag->attributes[$attribute] = $this->_getTranslationCode($varn);
            }
            // else if the attribute has a default value
            else if ($this->tag->hasAttribute($attribute)){
                // we use this default value as the translation key
                $key = $this->tag->getAttribute($attribute);
                $key = str_replace('\'', '\\\'', $key);
                $this->tag->attributes[$attribute] = $this->_getTranslationCode("'$key'");
            }
            else {
                // unable to translate the attribute
                throw new Exception("Unable to translate attribute $attribute");
            }
        }
    }

    public function end()
    {
    }

    private function _getTranslationCode($key)
    {
		$ckey = PHPTAL_Php_Attribute_I18N_Translate::_canonalizeKey($key);
		$code = '<?php ';
		if (preg_match_all('/\$\{(.*?)\}/', $key, $m)){
			array_shift($m);
			$m = array_shift($m);
			foreach ($m as $name){
				$code .= "\n".'$tpl->getTranslator()->setVar(\''.$name.'\','.phptal_tales($name).');';
			}
			$code .= "\n";
		}

        // notice the false boolean which indicate that the html is escaped
        // elsewhere looks like an hack doesn't it ? :)
		$result = $this->tag->generator->escapeCode(sprintf('$tpl->getTranslator()->translate(%s, false)', $ckey));
        $code .= 'echo '.$result.'?>';
		return $code;
    }
}

?>
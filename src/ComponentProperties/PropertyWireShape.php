<?php
/**
 * Component-property instance wire shapes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentProperties;

/**
 * Exact stored attribute shape consumed by the Etch component runtime.
 */
enum PropertyWireShape: string {

	case PLAIN_STRING_ATTRIBUTE = 'plain-string-attribute';

	case BOOLEAN_EXPRESSION_ATTRIBUTE = 'boolean-expression-attribute';

	case OBJECT_JSON_EXPRESSION_ATTRIBUTE = 'object-json-expression-attribute';

	case ARRAY_JSON_EXPRESSION_ATTRIBUTE = 'array-json-expression-attribute';

	case CLASS_STYLE_ID_LIST_ATTRIBUTE = 'class-style-id-list-attribute';

	case TRANSPARENT_CHILD_ATTRIBUTES = 'transparent-child-attributes';
}

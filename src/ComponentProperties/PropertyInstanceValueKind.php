<?php
/**
 * Semantic component-property instance value kinds.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentProperties;

/**
 * Names the authored value kind without implying a non-existent setter method.
 */
enum PropertyInstanceValueKind: string {

	case STRING = 'string';

	case NUMERIC_STRING = 'numeric-string';

	case BOOLEAN = 'boolean';

	case OBJECT = 'object';

	case ARRAY = 'array';

	case COLOR_STRING = 'color-string';

	case TRANSPARENT_CHILDREN = 'transparent-children';

	case LOOP_REFERENCE_STRING = 'loop-reference-string';

	case URL_STRING = 'url-string';

	case IMAGE_STRING = 'image-string';

	case SELECT_OPTION_STRING = 'select-option-string';

	case WORDPRESS_MEDIA_ID_STRING = 'wordpress-media-id-string';

	case CLASS_STYLE_SET = 'class-style-set';

	case REPEATER = 'repeater';

	case GROUP = 'group';
}

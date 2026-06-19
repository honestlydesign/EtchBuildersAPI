<?php
/**
 * Enum of Etch primitive property types.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Shared;

/**
 * Supported Etch component property primitive values.
 */
enum PropertyPrimitive: string {

	case STRING  = 'string';
	case NUMBER  = 'number';
	case BOOLEAN = 'boolean';
	case OBJECT  = 'object';
	case ARRAY   = 'array';
}

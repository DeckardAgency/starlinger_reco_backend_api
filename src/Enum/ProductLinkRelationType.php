<?php

namespace App\Enum;

/**
 * The kind of link one product has to another (ProductProductLink::$relationType).
 *
 * Stored as its string value in product_product_link.relation_type and exposed
 * verbatim over the API ("alternative" | "replacement"). The legacy link types
 * (Related/Upsell/Crossell/Bundle/Configurable) have no RECO equivalent.
 */
enum ProductLinkRelationType: string
{
    case Alternative = 'alternative';
    case Replacement = 'replacement';
}

<?php

namespace App\Controller;

use App\Service\OrderCheckoutService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * POST /api/v1/orders/checkout — one order per (managed) client, all-or-nothing.
 *
 * Registered as an API Platform operation on Order (see Order::class) with
 * `deserialize: false`: the payload is a cart grouped per client, not an Order,
 * so the body is read here as plain JSON and handed to OrderCheckoutService,
 * which owns the contract. Accepts `application/json` and `application/ld+json`.
 */
#[AsController]
final class OrderCheckoutController
{
    /** OpenAPI schema of the request body (referenced from the Order operation). */
    public const OPENAPI_REQUEST_SCHEMA = [
        'type' => 'object',
        'required' => ['groups'],
        'properties' => [
            'isDraft' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Save every order as a draft: no purchase-limit debit, no notifications, shippingAddressId optional.',
            ],
            'customerReference' => ['type' => 'string', 'nullable' => true, 'maxLength' => 100, 'example' => 'PO-123', 'description' => 'Copied to every order.'],
            'notes' => ['type' => 'string', 'nullable' => true, 'description' => 'Copied to every order.'],
            'paymentType' => ['type' => 'string', 'format' => 'iri-reference', 'nullable' => true, 'example' => '/api/v1/payment_types/1'],
            'deliveryType' => ['type' => 'string', 'format' => 'iri-reference', 'nullable' => true, 'example' => '/api/v1/delivery_types/2'],
            'groups' => [
                'type' => 'array',
                'minItems' => 1,
                'description' => 'One entry per client; each becomes one order. A client may not appear twice.',
                'items' => [
                    'type' => 'object',
                    'required' => ['items'],
                    'properties' => [
                        'onBehalfOfClient' => [
                            'type' => 'string',
                            'format' => 'iri-reference',
                            'nullable' => true,
                            'example' => '/api/v1/clients/12',
                            'description' => 'Managed client this group is ordered for (client agents only). null = the caller\'s own company.',
                        ],
                        'shippingAddressId' => [
                            'type' => 'integer',
                            'nullable' => true,
                            'example' => 55,
                            'description' => 'Id of an active delivery address of the group\'s client. Required unless isDraft.',
                        ],
                        'billingAddressId' => [
                            'type' => 'integer',
                            'nullable' => true,
                            'description' => 'Id of an active billing address of the group\'s client; defaults to its first active billing address.',
                        ],
                        'items' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['product', 'quantity'],
                                'properties' => [
                                    'product' => ['type' => 'string', 'format' => 'iri-reference', 'example' => '/api/v1/products/1'],
                                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'example' => 2],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    /** OpenAPI schema of the 201 response. */
    public const OPENAPI_RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'checkoutReference' => [
                'type' => 'string',
                'example' => 'CHK-20261018-A1B2C3',
                'description' => 'Shared by every order of this call; filter with GET /orders?checkoutReference=…',
            ],
            'orders' => [
                'type' => 'array',
                'description' => 'The created orders, one per group, serialized like the POST /orders response (JSON-LD, groups order:read, order:item, order_item:read).',
                'items' => ['type' => 'object'],
            ],
        ],
    ];

    public function __construct(private readonly OrderCheckoutService $checkout)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $content = (string) $request->getContent();
        if (trim($content) === '') {
            throw new BadRequestHttpException('Request body must be a JSON object with a "groups" array.');
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Request body is not valid JSON: ' . $e->getMessage(), $e);
        }

        // `{}` decodes to [] too; let the service report the missing "groups".
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new BadRequestHttpException('Request body must be a JSON object with a "groups" array.');
        }

        return new JsonResponse($this->checkout->checkout($payload), Response::HTTP_CREATED);
    }
}

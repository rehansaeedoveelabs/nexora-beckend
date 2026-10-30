<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'after' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
        $shop = $this->shop($request);
        $query = $shop->activityEvents()
            ->when($validated['type'] ?? null, fn ($events, string $type) => $events->where('type', $type))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
        $page = $query->cursorPaginate(
            (int) ($validated['first'] ?? 50),
            ['id', 'type', 'subject', 'summary', 'occurred_at'],
            'cursor',
            $validated['after'] ?? null,
        );

        return response()->json([
            'data' => $page->getCollection()->map(fn ($event) => [
                'id' => (string) $event->id,
                'type' => $event->type,
                'typeLabel' => match ($event->type) {
                    'routing_config_updated' => 'Routing configuration updated',
                    'location_settings_updated' => 'Location settings updated',
                    'location_types_bulk_updated' => 'Location types updated',
                    'order_fulfillment_reassigned' => 'Order fulfillment reassigned',
                    'order_automatically_routed' => 'Order automatically routed',
                    'order_automatic_routing_unchanged' => 'Automatic routing unchanged',
                    'order_automatic_routing_skipped' => 'Automatic routing skipped',
                    'order_automatic_routing_partial' => 'Automatic routing partially completed',
                    'shopify_order_created' => 'Shopify order created',
                    'shopify_order_updated' => 'Shopify order updated',
                    'shopify_order_cancelled' => 'Shopify order cancelled',
                    'shopify_fulfillment_created' => 'Shopify fulfillment created',
                    'shopify_fulfillment_updated' => 'Shopify fulfillment updated',
                    default => 'Nexora change',
                },
                'subject' => $event->subject,
                'summary' => $event->summary,
                'at' => $event->occurred_at->toIso8601String(),
            ])->values(),
            'meta' => [
                'page_info' => [
                    'has_next_page' => $page->hasMorePages(),
                    'end_cursor' => $page->nextCursor()?->encode(),
                ],
            ],
        ]);
    }
}

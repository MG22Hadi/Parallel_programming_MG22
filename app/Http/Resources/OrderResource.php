<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'order_id' => $this->id,
            'status' => $this->status,
            'payment_intent_id' => $this->payment_intent_id,
        ];
    }
}

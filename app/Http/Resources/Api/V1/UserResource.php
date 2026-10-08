<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sector_id' => $this->sector_id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'phone' => $this->phone,
            'roles' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->pluck('name')->values(),
            ),
            'sector' => $this->whenLoaded(
                'sector',
                fn () => $this->sector === null
                    ? null
                    : ['id' => $this->sector->id, 'name' => $this->sector->name],
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

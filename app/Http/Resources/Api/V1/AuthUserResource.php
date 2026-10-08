<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
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
        ];
    }
}

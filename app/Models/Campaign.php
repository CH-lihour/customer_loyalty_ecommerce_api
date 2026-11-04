<?php

namespace App\Models;

use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'name_kh', 'description', 'image', 'banner_title', 'banner_subtitle', 'button_label', 'link_url',
    'multiplier', 'start_date', 'end_date', 'is_active', 'show_on_home', 'sort_order',
])]
#[Appends(['image_url'])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    /**
     * The disk uploaded banner images are stored on.
     */
    public const DISK = 'public';

    /**
     * The fields the public home-page banners expose.
     */
    public const BANNER_FIELDS = [
        'id', 'name', 'name_kh', 'banner_title', 'banner_subtitle', 'button_label', 'link_url',
        'image_url', 'start_date', 'end_date', 'multiplier',
    ];

    /**
     * Limit to campaigns that are switched on and running today.
     *
     * @param  Builder<Campaign>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $today = now()->toDateString();

        $query->where('is_active', true)
            ->whereDate('start_date', '<=', $today)
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today));
    }

    /**
     * Whether the image was uploaded here, rather than being an external URL.
     */
    public function hasUploadedImage(): bool
    {
        return $this->image !== null && ! Str::startsWith($this->image, ['http://', 'https://']);
    }

    /**
     * Remove the stored file of an uploaded image.
     */
    public function deleteImageFile(): void
    {
        if ($this->hasUploadedImage()) {
            Storage::disk(self::DISK)->delete($this->image);
        }
    }

    /**
     * The address clients load the image from. Built from the request host, so it is right whatever APP_URL says.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->hasUploadedImage() ? url('storage/'.$this->image) : $this->image);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'multiplier' => 'decimal:2',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'show_on_home' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}

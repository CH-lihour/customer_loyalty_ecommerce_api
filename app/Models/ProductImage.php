<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['product_id', 'path', 'sort_order'])]
#[Hidden(['path', 'product_id', 'created_at', 'updated_at'])]
#[Appends(['url'])]
class ProductImage extends Model
{
    /**
     * The disk uploaded product images are stored on.
     */
    public const DISK = 'public';

    /**
     * Get the product the image belongs to.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Whether the image was uploaded here, rather than being an external URL (seeded and migrated images).
     */
    public function isUploaded(): bool
    {
        return ! Str::startsWith($this->path, ['http://', 'https://']);
    }

    /**
     * Remove the stored file of an uploaded image.
     */
    public function deleteFile(): void
    {
        if ($this->isUploaded()) {
            Storage::disk(self::DISK)->delete($this->path);
        }
    }

    /**
     * The address clients load the image from. Built from the request host, so it is right whatever APP_URL says.
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => $this->isUploaded() ? url('storage/'.$this->path) : $this->path);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}

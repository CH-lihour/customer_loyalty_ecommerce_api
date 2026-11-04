<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row of the product stock ledger, signed so sales are negative. Reward stock has its own ledger.
 */
#[Fillable(['product_id', 'warehouse_id', 'type', 'quantity', 'reference_type', 'reference_id', 'note'])]
class ProductStockMovement extends Model
{
    public const SALE = 'SALE';

    public const RETURN = 'RETURN';

    public const ADJUST = 'ADJUST';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'stock_movements';

    /**
     * Get what caused the movement, such as an order.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}

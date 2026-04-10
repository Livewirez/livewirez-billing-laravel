<?php 

namespace Livewirez\Billing\Lib\Polar\Traits;

use Livewirez\Billing\Lib\Polar\Data;
use Illuminate\Database\Eloquent\Model;

trait HasTransformedModel 
{

    public ?Model $model = null;

    public function getModel(): ?Model
    {
        return $this->model;
    }

    public function setModel(?Model $model = null): static
    {
        $this->model = $model;
        
        return $this;
    }
}
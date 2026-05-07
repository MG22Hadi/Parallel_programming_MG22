<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\CartService;

class CartController extends Controller
{
    private $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    public function add(Request $request)
    {
        return response()->json(
            $this->cartService->addToCart(
                $request->user(),
                $request->product_id,
                $request->quantity
            )
        );
    }

    public function update(Request $request)
    {
        return response()->json(
            $this->cartService->updateItem(
                $request->user(),
                $request->product_id,
                $request->quantity
            )
        );
    }

    public function remove(Request $request)
    {
        return response()->json(
            $this->cartService->removeItem(
                $request->user(),
                $request->product_id
            )
        );
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->cartService->getCart($request->user())
        );
    }
}

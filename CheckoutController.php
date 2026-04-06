<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\ShiprocketService;

class CheckoutController extends Controller
{
    private function getCustomer()
    {
        return auth()->user()?->customer;
    }

    /**
     * Checkout summary with seller-wise shipping
     */
    public function summary(Request $request, ShiprocketService $shiprocket): JsonResponse
    {
        $request->validate([
            'shipping_address_id' => 'required|exists:customer_addresses,id',
        ]);

        $customer = $this->getCustomer();
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $address = CustomerAddress::where('id', $request->shipping_address_id)
            ->where('customer_id', $customer->id)
            ->first();
        if (!$address) {
            return response()->json(['success' => false, 'message' => 'Invalid shipping address'], 400);
        }

        $cart = Cart::where('customer_id', $customer->id)
            ->with(['items.product.seller', 'items.variant', 'items.variant.images', 'items.product.images'])
            ->first();
        if (!$cart || $cart->items->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Cart is empty'], 400);
        }

        $items = [];
        $priceSummary = ['subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0];

        // Group items by seller
        $itemsBySeller = $cart->items->groupBy(fn($item) => $item->product->seller_id);

        foreach ($itemsBySeller as $sellerId => $sellerItems) {
            $seller = $sellerItems->first()->product->seller;

            // Total weight & subtotal for this seller
            $weight = $sellerSubtotal = 0;
            foreach ($sellerItems as $item) {
                $variant = $item->variant;
                $price = $variant->sale_price ?? $variant->price;
                $sellerSubtotal += $price * $item->quantity;
                $weight += ($variant->weight ?? 0.5) * $item->quantity;
            }

            // Seller pickup pincode
            $pickupPincode = $seller->pincode ?? config('services.shiprocket.pickup_pincode');

            // Shipping rate via Shiprocket
            $rate = $shiprocket->getRates($pickupPincode, $address->pincode, $weight);
            $sellerShipping = $rate['data']['available_courier_companies'][0]['rate'] ?? 0;

            // Add each product
            foreach ($sellerItems as $item) {
                $variant = $item->variant;
                $price = $variant->sale_price ?? $variant->price;
                $itemTotal = $price * $item->quantity;

                $image = $variant->images->first()?->image
                    ?? $item->product->images->first()?->image;

                $items[] = [
                    'product_id' => $item->product_id,
                    'name' => $item->product->name,
                    'image' => $image,
                    'quantity' => $item->quantity,
                    'price' => $price,
                    'total' => $itemTotal,
                    'seller_id' => $seller->id,
                    'seller_shop' => $seller->shop_name,
                    'weight' => ($variant->weight ?? 0.5) * $item->quantity,
                    'shipping' => $sellerShipping
                ];
            }

            $tax = ($sellerSubtotal * 5) / 100;
            $priceSummary['subtotal'] += $sellerSubtotal;
            $priceSummary['tax'] += $tax;
            $priceSummary['shipping'] += $sellerShipping;
        }

        $priceSummary['total'] = $priceSummary['subtotal'] + $priceSummary['tax'] + $priceSummary['shipping'];

        return response()->json([
            'success' => true,
            'data' => [
                'shipping_address' => [
                    'name' => $address->receiver_name,
                    'phone' => $address->phone,
                    'address_line1' => $address->address_line_1,
                    'address_line2' => $address->address_line_2,
                    'city' => $address->city,
                    'state' => $address->state,
                    'pincode' => $address->pincode,
                    'country' => $address->country,
                ],
                'products' => $items,
                'price_summary' => $priceSummary
            ]
        ]);
    }

    /**
     * Store order with seller-wise shipping
     */
    public function store(Request $request, ShiprocketService $shiprocket): JsonResponse
    {
        $request->validate([
            'shipping_address_id' => 'required|exists:customer_addresses,id',
            'payment_method' => 'required|in:cod,stripe,razorpay',
        ]);

        $customer = $this->getCustomer();
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $address = CustomerAddress::where('id', $request->shipping_address_id)
            ->where('customer_id', $customer->id)
            ->first();
        if (!$address) {
            return response()->json(['success' => false, 'message' => 'Invalid shipping address'], 400);
        }

        $cart = Cart::where('customer_id', $customer->id)
            ->with(['items.product.seller', 'items.variant', 'items.variant.images', 'items.product.images'])
            ->first();
        if (!$cart || $cart->items->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Cart is empty'], 400);
        }

        DB::beginTransaction();
        try {
            $orders = [];
            $totalPayable = 0;

            // Group items by seller
            $itemsBySeller = $cart->items->groupBy(fn($item) => $item->product->seller_id);

            $payment = Payment::create([
                'payment_method' => $request->payment_method,
                'amount' => 0,
                'status' => Order::PAYMENT_PENDING,
                'transaction_id' => (string) Str::uuid(),
            ]);

            foreach ($itemsBySeller as $sellerId => $sellerItems) {
                $seller = $sellerItems->first()->product->seller;

                // Calculate total weight & seller shipping
                $weight = $sellerSubtotal = 0;
                foreach ($sellerItems as $item) {
                    $variant = $item->variant;
                    $price = $variant->sale_price ?? $variant->price;
                    $sellerSubtotal += $price * $item->quantity;
                    $weight += ($variant->weight ?? 0.5) * $item->quantity;

                    if ($item->quantity > $variant->stock) {
                        throw new \Exception("Stock not available for {$item->product->name}");
                    }
                }

                $pickupPincode = $seller->pincode ?? config('services.shiprocket.pickup_pincode');
                $rate = $shiprocket->getRates($pickupPincode, $address->pincode, $weight);
                $sellerShipping = $rate['data']['available_courier_companies'][0]['rate'] ?? 0;

                foreach ($sellerItems as $item) {
                    $variant = $item->variant;
                    $price = $variant->sale_price ?? $variant->price;
                    $subtotal = $price * $item->quantity;
                    $tax = ($subtotal * 5) / 100;
                    $commission = ($subtotal * 10) / 100;
                    $total = $subtotal + $tax + $sellerShipping;

                    $image = $variant->images->first()?->image ?? $item->product->images->first()?->image;

                    $productDetails = [
                        'product_id' => $item->product_id,
                        'name' => $item->product->name,
                        'image' => $image,
                        'variant_id' => $variant->id
                    ];

                    $order = Order::create([
                        'order_number' => 'ORD-' . strtoupper(Str::random(10)),
                        'customer_id' => $customer->id,
                        'seller_id' => $item->product->seller_id,
                        'payment_id' => $payment->id,
                        'customer_details' => [
                            'id' => $customer->id,
                            'name' => $customer->name,
                            'email' => $customer->email,
                            'phone' => $customer->phone,
                        ],
                        'shipping_details' => [
                            'name' => $address->receiver_name,
                            'phone' => $address->phone,
                            'address_line1' => $address->address_line_1,
                            'address_line2' => $address->address_line_2,
                            'city' => $address->city,
                            'state' => $address->state,
                            'pincode' => $address->pincode,
                            'country' => $address->country,
                        ],
                        'product_details' => $productDetails,
                        'quantity' => $item->quantity,
                        'price' => $price,
                        'subtotal' => $subtotal,
                        'tax_amount' => $tax,
                        'shipping_amount' => $sellerShipping,
                        'commission_amount' => $commission,
                        'total_amount' => $total,
                        'payment_method' => $request->payment_method,
                        'payment_status' => Order::PAYMENT_PENDING,
                        'order_status' => Order::STATUS_PENDING,
                        'currency' => 'INR',
                    ]);

                    $variant->decrement('stock', $item->quantity);
                    $totalPayable += $total;
                    $orders[] = $order;
                }
            }

            $payment->update(['amount' => $totalPayable]);
            $cart->items()->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully',
                'payment_id' => $payment->id,
                'total_amount' => $totalPayable,
                'data' => OrderResource::collection($orders)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}

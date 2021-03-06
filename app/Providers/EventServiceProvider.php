<?php

namespace App\Providers;

use App\Events\OrderPaid;
use App\Events\OrderRefund;
use App\Events\OrderReviewed;
use App\Listeners\SendOrderPaidMail;
use App\Listeners\SendOrderRefundMail;
use App\Listeners\UpdateCrowdfundingProductProgress;
use App\Listeners\UpdateProductRating;
use App\Listeners\UpdateProductSoldCount;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     * @var array
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        // 将支付宝的事件和对应的监听器关联起来
        OrderPaid::class => [
            UpdateProductSoldCount::class, // 更新商品的销量
            SendOrderPaidMail::class, // 发送支付成功的邮件
            UpdateCrowdfundingProductProgress::class, // 更新众筹进度
        ],
        OrderReviewed::class => [
            UpdateProductRating::class // 更新商品评价数量和评分
        ],
        OrderRefund::class => [
            SendOrderRefundMail::class, // 发送订单退款成功邮件
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        //
    }
}

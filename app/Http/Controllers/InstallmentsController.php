<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Exceptions\InvalidRequestException;
use App\Models\Installment;
use App\Models\InstallmentItem;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InstallmentsController extends Controller
{

    /**
     * 分期付款的列表
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request)
    {
        $installments = Installment::query()
            ->where('user_id',$request->user()->id)
            ->paginate(10);
        return view('installments.index',['installments' => $installments]);
    }

    /**
     * 分期付款的详情
     * @param Installment $installment
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function show(Installment $installment)
    {
        $this->authorize('own',$installment);

        // 取出当前分期付款的所有还款计划. 并按还款顺序排序
        $items = $installment->items()->orderBy('sequence')->get();
        return view('installments.show',[
            'installment'   => $installment,
            'items' => $items,
            // 下一个未完成的还款计划
            'nextItem'  => $items->where('paid_at',null)->first()
        ]);
    }

    /**
     * @param Installment $installment
     * @return mixed
     * @throws InvalidRequestException
     */
    public function payByAlipay(Installment $installment)
    {
        if ($installment->order->closed) {
            throw new InvalidRequestException('对应的订单已被关闭');
        }

        if ($installment->status === Installment::STATUS_FINISHED) {
            throw new InvalidRequestException('该分期订单已结清');
        }

        /** @var InstallmentItem |null  $nextItem */
        if (!$nextItem = $installment->items()->whereNull('paid_at')->orderBy('sequence')->first()) {
            // 如果没有未支付的还款，原则上不可能，因为如果分期已结清则在上一个判断就退出了
            throw new InvalidRequestException('该分期订单已结清');
        }

        // 调用支付宝的网页支付
        return app('alipay')->web([
            // 支付订单号使用分期流水号+还款计划编号
            'out_trade_no'  => $installment->no.'_'.$nextItem->sequence,
            'total_amount'  => $nextItem->total,
            'subject'   =>  '支付  分期订单:'.$installment->no,
            'notify_url'   => route('installments.alipay.notify'),
            'return_url'   => route('installments.alipay.return'),
        ]);
    }

    // 支付宝前端回调
    public function alipayReturn()
    {
        \Log::debug('分期付款,同步跳转');
        try {
            app('alipay')->verify();
        } catch (\Exception $e) {
            return view('pages.error', ['msg' => '数据不正确']);
        }

        return view('pages.success', ['msg' => '付款成功']);
    }

    /**
     * 支付宝 异步回调
     * @throws \Throwable
     */
    public function alipayNotify()
    {
        $data = app('alipay')->verify();

        // 如果订单状态不是成功或者结束，则不走后续的逻辑
        if (!in_array($data->trade_status, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
            return app('alipay')->success();
        }

        // 拉起支付时使用的支付订单号是由分期流水号 + 还款计划编号组成的
        // 因此可以通过支付订单号来还原出这笔还款是哪个分期付款的哪个还款计划
        list($no,$sequence) = explode('_',$data->out_trade_no);
        \Log::info('分期付款 异步回调.',['no' => $no,'sequence' => $sequence]);
        // 根据分期流水号查询对应的分期记录，原则上不会找不到，这里的判断只是增强代码健壮性
        /** @var Installment $installment */
        if (!$installment = Installment::where('no', $no)->first()) {
            return 'fail';
        }

        // 根据还款计划编号查询对应的还款计划，原则上不会找不到，这里的判断只是增强代码健壮性
        /** @var InstallmentItem $item */
        if (!$item = $installment->items()->where('sequence', $sequence)->first()) {
            return 'fail';
        }


        // 如果这个还款计划的支付状态是已支付，则告知支付宝此订单已完成，并不再执行后续逻辑
        if ($item->paid_at) {
            return app('alipay')->success();
        }

        \DB::transaction(function () use ($data,$no, $installment, $item) {

            $item->update([
                'paid_at'   => Carbon::now(),
                'payment_method'    => 'alipay', // 支付方式:支付宝
                'payment_no'    => $data->trade_no // 支付宝的订单号
            ]);


            // 如果这是第一笔还款
            if ($item->sequence === 0) {
                // 将分期付款状态改成 还款中
                $installment->update(['status' => Installment::STATUS_PENDING]);

                // 将分期付款对应的商品订单状态改为已支付
                $installment->order->update([
                    'paid_at'   => Carbon::now(),
                    'payment_method'    => 'installment', // 将订单的支付方式改为分期付款
                    'payment_no'    => $no
                ]);

                event(new OrderPaid($installment->order));
            }

            // 如果是最后一笔还款
            if ($item->sequence === $installment->count - 1) {
                // 将分期付款状态改为已结清
                $installment->update(['status' => Installment::STATUS_FINISHED]);
            }
        });

        return app('alipay')->success();
    }
}

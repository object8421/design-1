<?php
class Utils_Process
{
    //dsp对象
    public static $dsp_instance;
    //ssp对象
    public static $ssp_instance;

    public static $sspbid = array('se');
    //是否是香港环境
    public static $hk = false;
    
    public static function common($ssp_name)
    {
        $log = array('ssp' => $ssp_name);
        $log = array('unixpid' => posix_getpid());
        $log_time = [];
        $response = array();
        $std_req = array();
        
        // 1.将 ssp_request 转换成 std_request
        $t1 = microtime(true);
        $ssp_ref = new ReflectionClass($ssp_name);
        static::$ssp_instance = $ssp_ref->newInstance();
        $ssp_init = static::$ssp_instance->init();
        if (!empty($ssp_init))
        {
            $log['error'] = $ssp_name.' init fail';
            goto END;
        }
        $std_req_arr = static::$ssp_instance->standardize();
        if (!empty($std_req_arr['error']))
        {
            $log['error'] = $std_req_arr['error'];
            goto END;
        }

        $std_req = $std_req_arr['data'];
        $log['req_token'] = $std_req->req_token;
        self::write_req_log($std_req);
        $t2 = microtime(true);
        $log_time['req_adaptor_time'] = sprintf("%lf", $t2 - $t1);
        
        // 2.ssp流量路由确定
        $t1 = microtime(true);
        $dsp_arr = Route_Default::route($std_req);
        $t2 = microtime(true);
        $log_time['route_time'] = sprintf("%lf", $t2 - $t1);

        // 3.将 std_request 转换成 dsp_request，并准备dsp的curl句柄
        $t1 = microtime(true);
        $chs = array();
        $enable_dsp = array();
        foreach ($dsp_arr as $dsp)
        {
            if (!class_exists($dsp))
            {
                $log[$dsp.' is not impl'] = 1;
                continue;
            }
            $ref = new ReflectionClass($dsp);
            $obj = $ref->newInstance();
            $dsp_init_errstr = $obj->init();
            if (!empty($dsp_init_errstr))
            {
                $log[$dsp.' init fail'] = $dsp_init_errstr;
                continue;
            }
            static::$dsp_instance[$dsp] = $obj;
            $ch_arr = $obj->deal($std_req);
            if (!empty($ch_arr['error']))
            {
                $log[$dsp.' init fail'] = $ch_arr['error'];
                continue;
            }
            $enable_dsp[] = $dsp;
            $chs[$dsp] = $ch_arr['data'];
        }
        if (empty($chs))
        {
            $log['no avaliable dsp'] = 1;
            goto END;
        }
        $t2 = microtime(true);
        $log_time['dspreq_adaptor_time'] = sprintf("%lf", $t2 - $t1);

        // 4.向dsp并行发送请求
        $t1 = microtime(true);
        $multi_curl = new Utils_Multicurl($chs, $enable_dsp);
        $res = $multi_curl->run();
        $http_res = $multi_curl->get_http_res();
        $dsp_req_log_arr = [];
        foreach ($res as $dsp => $inf)
        {
            $dsp_status = [];
            // http response status
            if($res[$dsp] === FALSE)
            {
                unset($res[$dsp]);
                $dsp_status['req_succ'] = 0; // failed
            }
            else
            {
                $dsp_status['req_succ'] = 1; // success
            }
            // no return ads
            $dsp_status['ret_ads']      = 'N';
            $dsp_status['errno']        = $http_res[$dsp]['errno'];
            $dsp_status['http_code']    = $http_res[$dsp]['http_code'];
            $dsp_req_log_arr[$dsp] = $dsp_status;
        }
        
        if (empty($res))
        {
            $log['all dsp filtered'] = 1;
        }
        $t2 = microtime(true);
        $log_time['dspreq_time'] = sprintf("%lf", $t2 - $t1);

        // 5.将 dsp_response 转换成 std_response
        $t1 = microtime(true);
        $std_response = array();
        foreach ($res as $dsp => $inf)
        {
            $dsp_response = $inf['content'];
            $std_res = static::$dsp_instance[$dsp]->get_std_response($std_req, $dsp_response);
            if (!empty($std_res['error']))
            {
                $log['get '.$dsp.' response fail'] = $std_res['error'];
                continue;
            }
            $std_response[$dsp] = $std_res['data'];
            // return ads
            $dsp_req_log_arr[$dsp]['ret_ads'] = 'Y';
        }
        // write dsp req log
        foreach($dsp_req_log_arr as $dsp => $restmp)
        {
            $std_req->res_info[$dsp] = $restmp;
            self::write_dsp_req_log($std_req, $dsp);
        }
        if (empty($std_response))
        {
            $log['all dsp get_std_response fail'] = 1;
            goto END;
        }
        $t2 = microtime(true);
        $log_time['dspres_adaptor_time'] = sprintf("%lf", $t2 - $t1);

        // 6.对dsp的响应结果实行竞价策略
        $t1 = microtime(true);
        $win_dsp = Utils_Rtb::RTB($std_req, $std_response);
        $t2 = microtime(true);
        $log_time['rtb_time'] = sprintf("%lf", $t2 - $t1);
        //输出adx竞价日志
        foreach ($std_response as $tmpres)
        {
            self::write_bid_log($std_req, $tmpres);
        }
        if (!empty($win_dsp))
        {
            //输出ssp竞价日志
            if (in_array($std_req->name, static::$sspbid) && !empty($win_dsp))
            {
                $sspbid = static::$ssp_instance->sspbid($std_req, $std_response[$win_dsp]);
                if (empty($std_req->priv_dsp_id)) Utils_Dclog::write_sspbid_log($sspbid); #扩容流量不输出二次竞价日志
            }
        
            // 7.通知赢得竞价的dsp：You win!
            $t1 = microtime(true);
            $log['res_token'] = $std_response[$win_dsp]->bidid;
            static::$dsp_instance[$win_dsp]->send_win_price($std_req, $std_response[$win_dsp]);
            $t2 = microtime(true);
            $log_time['sendwin_time'] = sprintf("%lf", $t2 - $t1);
            // 8.将 std_response 转换成 ssp_response
            $t1 = microtime(true);
            $win_ssp_response = static::$ssp_instance->adapter_res($std_req, $std_response[$win_dsp]);
            $response = $win_ssp_response['data'];
            $t2 = microtime(true);
            $log_time['adapterres_time'] = sprintf("%lf", $t2 - $t1);
        }
END:
        Utils_Stlog::log(array(__METHOD__ => $log));
        Utils_Stlog::log(array('process_time' => $log_time));
        // 8.响应ssp请求
        static::$ssp_instance->display($std_req, $response);
    }

    protected static function write_req_log($std_req)
    {
        if (Utils_Common::is_hk())
        {
            return true;
        }
        if (!empty($std_req->priv_dsp_id))
        {
            Utils_Dclog::write_req_log($std_req, 'req_kr.tmp');
        }
        else
        {
            Utils_Dclog::write_req_log($std_req);
        }
    }

    protected static function write_dsp_req_log($std_req, $dsp_name)
    {
        if (!empty($std_req->priv_dsp_id))
        {
            Utils_Dclog::write_dsp_req_log($std_req, $dsp_name, 'dspreq_kr.tmp');
        }
        else
        {
            Utils_Dclog::write_dsp_req_log($std_req, $dsp_name);
        }
    }

    protected static function write_bid_log($std_req, $tmp_res)
    {
        if (Utils_Common::is_hk())
        {
            Utils_Dclog::write_hkbid_log($std_req, $tmp_res);
        }
        elseif (!empty($std_req->priv_dsp_id))
        {
            Utils_Dclog::write_bid_log($std_req, $tmp_res, 'bid_kr.tmp');
        }
        else
        {
            Utils_Dclog::write_bid_log($std_req, $tmp_res);
        }
    }
}
?>

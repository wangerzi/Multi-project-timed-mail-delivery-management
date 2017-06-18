<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2017/6/15
 * Time: 14:57
 */
return array(
    'host'    =>  '127.0.0.1',    //redis服务器地址
    'port'    =>  6379,           //redis端口
    'auth'    =>  '',             //密码-为空则不验证密码
    'timeout' =>  0.0,            //连接参数中的超时选项，0.0意味着不限制
    'key_pre' =>  'wq_',          //sort set的键名前缀，用来防止重名
);
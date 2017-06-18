<?php
namespace wq_email\dev;
/**
 * Redis驱动实现的方法与db驱动实现的方法很不一样，因为提供for检索删除邮件功能，所以，每次添加定时邮件时，都会使用List wq_emailTimeFor+for做一个索引，里边防止for对应的所有邮件，不然找不到。
 * User: Administrator
 * Date: 2017/6/15
 * Time: 15:20
 */
class Redis
{
    private $conf = array(
        'host'    =>  '127.0.0.1',    //redis服务器地址
        'port'    =>  6379,           //redis端口
        'auth'    =>  '',             //密码-为空则不验证密码
        'timeout' =>  0.0,            //0.0意味着不限制
        'key_pre' =>  'wq_',          //sort set的键名前缀，用来防止重名
    );
    private $redis;//连接对象
    function __construct($conf)
    {
        $conf = $this->conf = array_merge($this->conf,$conf);//合并配置

        $redis = new \Redis();
        $res = $redis->connect($conf['host'],$conf['port'],$conf['timeout']);

        //检查连接
        if(false === $res){
            echo 'redis connect failed.';
            die();
        }

        //进行验证
        if(!empty($conf['auth']))
            $redis->auth($conf['auth']);

        $this->redis = $redis;
    }

    /**
     * 邮件驱动实现添加邮件队列，数组内的参数意义与MyEmail.class.php同名函数一样，注意：对同一个人，同一个name，同时间，同邮箱，同内容（全部相同的话），sort set会自动屏蔽重复 -- 不过一般也不会。
     * @param $arr
     * @return bool
     */
    function addEmailTimeQueue($arr){
        $redis = $this->redis;
        $conf = $this->conf;

        //这样写是为了过滤下无用的key，防止查找出现问题
        $data = array(
            'email'    =>  $arr['email'],
            'title'    =>  $arr['title'],
            'name'     =>  $arr['name'],
            'content'  =>  $arr['content'],
            'time'     =>  $arr['time'],
            'repeat'   =>  $arr['repeat'],
            'for'      =>  $arr['for'],
            'is_function'=> $arr['is_function'],
            'fail_callback'=> $arr['fail_callback'],
        );
        $data = json_encode($data);
        //使用sort set和List的形式   --- 注意：这里不能用data，因为data已经是字符串了。
        $res = $redis->zAdd($conf['key_pre'].'emailTimeQueue',$arr['time'],$data);//有序队列的分值为发送时间
        $res = $res && $redis->rPush($conf['key_pre'].'emailTimeFor'.$arr['for'],$data);//相当于自建索引，用于查找for对应的所有的数据，但感觉这样耗费双倍内存总有点不爽。。。
        return $res;
    }

    /**
     * 添加入即将发送的邮件队列，$arr中的参数含义与MyEmail.class.php中的同名函数相同。
     * @param $arr
     * @return bool
     */
    function addEmailQueue($arr){
        $redis = $this->redis;
        $conf = $this->conf;

        $data = array(
            'email'    =>  $arr['email'],
            'title'    =>  $arr['title'],
            'name'     =>  $arr['name'],
            'content'  =>  $arr['content'],
            'for'      =>  $arr['for'],
            'fail_callback'=> $arr['fail_callback'],
        );

        $data = json_encode($data);

        return $redis->rPush($conf['key_pre'].'emailQueue',$data);//这里用rPush有利于实现先进先出
    }

    /**
     * 获取即将发送的邮件
     * @param $limit
     * @return int|array
     */
    function getEmailQueue($limit){
        $redis = $this->redis;
        $conf = $this->conf;

        $limit = $limit-1;
        $ans = $redis->lRange($conf['key_pre'].'emailQueue',0,$limit);//先进先出，根据规则：小心limit=1应该写入0，limit=0应该写入-1
        //没有直接返回
        if(empty($ans))
            return 0;
        //有的话进行转义
        $arr = [];
        foreach ($ans as $key=>$value){
            $arr[] = json_decode($value,true);
        }
        return $arr;
    }
    /**
     * 获取即将需要发送送队列的邮件
     * @param $limit
     * @return int|array
     */
    function getEmailTimeQueue($limit){
        $redis = $this->redis;
        $conf = $this->conf;

        $ans = $redis->zRangeByScore($conf['key_pre'].'emailTimeQueue',0,time(),[ 'limit'=>[0,$limit] ]);

        //同上一函数
        if(empty($ans))
            return 0;

        //TODO::将key=>value转换为0=>key，这里出来的数据不能直接用 --这是带score的时候。
        $data = [];
        foreach ($ans as $key=>$value){
            //$tmp = json_decode($key,true);
            //$tmp['time'] = $value;//分值即为发送时间。
            $tmp = json_decode($value,true);
            $data[] = $tmp;
        }
        return $data;
    }

    /**
     * 错误次数加一
     * @param $arr
     * @return bool
     */
    function errorTimePlus($arr){
        $redis = $this->redis;
        $conf = $this->conf;

        $data = json_encode($arr);
        $redis->lRem($conf['key_pre'].'emailQueue',$data,1);//先把之前的删掉
        $redis->rPush($conf['key_pre'].'emailQueue',$data);//置于处理即将发送邮件的末尾。
    }

    /**
     * 批量添加邮件
     * @param $data
     * @return int
     */
    function addEmailAll($data){
        $redis = $this->redis;
        $conf = $this->conf;

        $count = 0;
        foreach ($data as $arr){
            if($redis->rPush($conf['key_pre'].'emailQueue',json_encode($arr)))
                $count++;
        }
        return $count;
    }

    /**
     * 批量删除邮件定时队列中的数据
     * @param $data
     * @return int
     */
    function delEmailTimeAll($data){
        $redis = $this->redis;
        $conf = $this->conf;

        $count = 0;
        foreach ($data as $arr){
            $tmp = json_encode($arr);
            if($redis->zRem($conf['key_pre'].'emailTimeQueue',$tmp) && $redis->lRem($conf['key_pre'].'emailTimeFor'.$arr['for'],$tmp,0))//不仅清除sort set中的，还清除list队列中的。
                $count++;
        }
        return $count;
    }

    /**
     * 批量推迟邮件队列中的邮件
     * @param $data
     * @return int
     */
    function delayEmailTimeAll($data){
        $redis = $this->redis;
        $conf = $this->conf;

        $count = 0;
        $del = [];
        foreach ($data as $key=>$arr){
            $newTime = $arr['newTime'];
            unset($arr['newTime']);//过滤这个newTime，不然查不到。。。
            $del[] = $arr;
            $arr['time'] = $newTime;
            $this->addEmailTimeQueue($arr);//这个函数，不支持批量。
        }
        //print_r($del);
        //相当于删了重新加
        $this->delEmailTimeAll($del);
        return $count;
    }

    /**
     * 删除待发送区的邮件，仅限内部使用。
     * @param $data
     * @return int
     */
    function delEmailAll($data){
        $redis = $this->redis;
        $conf = $this->conf;

        $count = 0;
        foreach ($data as $arr){
            if($redis->lRem($conf['key_pre'].'emailQueue',json_encode($arr),0))
                $count++;
        }
        return $count;
    }

    /**
     * 通过for属性查找相关的邮件，并删除
     * @param $for
     * @param $limit
     * @return bool
     */
    function delEmailTimeByFor($for,$limit){
        $redis = $this->redis;
        $conf = $this->conf;
        $limit = intval($limit);

        $data = $redis->lRange($conf['key_pre'].'emailTimeFor'.$for,0,-1);//全部获取到。
        if(empty($data))
            return 0;
        $i=0;
        foreach ($data as $key=>$value){
            if($limit!=0 && $i>=$limit)//只删除limit个，当limit=0的时候全删除
                break;
            $redis->zRem($conf['key_pre'].'emailTimeQueue',$value);
            $redis->lRem($conf['key_pre'].'emailTimeFor'.$for,$value,0);
            $i++;
        }
    }
}
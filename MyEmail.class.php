<?php

/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2017/3/1
 * Time: 18:42
 * Info: 阅读指南：
 *          建议在 ./demo/demo-test.php中先将 MyEmail::addEmailTimeQueue()函数的用法弄清，本系统即可正确使用了。
 *          ./Conf/db.php 和 ./Conf/mail.php的配置和 php.ini中pdo相关配置项的开启和 php_openssl 配置项的开启，是使用本系统的关键。
 *          本系统实现原理为：
 *              通过调用addEmailTimeQueue()函数，将定时邮件存入数据库 -- 开发者编程时调用。
 *              通过调用delEmailTimeQueue($for,$limit)管理定时邮件。
 *              通过dealEmailTimeQueue(),dealEmailQueue()来控制定时邮件->待发送区 和 待发送区->发出邮件。 -- time.php已经做好
 *              用户用 批处理或 Linux Shell 执行time.php脚本，实现定时邮件的控制（一直执行着，放在一旁即可）
 *          README.md中也蕴含有不少信息。
 */
use \wq_email\dev\Db;
use \wq_email\dev\Redis;
defined('__MAIL_ROOT__') or define('__MAIL_ROOT__',dirname(__FILE__).'/');
class MyEmail
{
    private $loadedFile=array();//已经加载过的文件，避免重复加载。
    private $handle = null;//存储驱动对象
    //构造函数，配置各种信息
    function __construct()
    {
        //邮件配置。
        $this->mailConf=include __MAIL_ROOT__."Conf/mail.php";
        $driver = strtolower($this->mailConf['MAIL_DRIVER']);
        switch ($driver){
            case 'db':
                //数据库配置
                $dbConf = include __MAIL_ROOT__ . "Conf/db.php";
                if(!class_exists('\wq_email\dev\Db')) {//只在没有的时候加载，避免错误。
                    //加载驱动文件
                    $this->includeExtFile('Driver/Db.php');
                }
                $this->handle = new db($dbConf);
                break;
            case 'redis':
                $conf = include __MAIL_ROOT__ . 'Conf/redis.php';
                if(!class_exists('\wq_email\dev\Redis')) {//只在没有的时候加载，避免错误。
                    $this->includeExtFile(__MAIL_ROOT__ . 'Driver/Redis.php');
                }
                $this->handle = new Redis($conf);
                break;
            default:
                echo '不支持的驱动类型';
                die();

        }
        if(!class_exists('PHPMailer')) {
            //加载phpMailer，预先加载，避免相对定位点改变后加载失败。
            $this->includeExtFile(__MAIL_ROOT__ . $this->mailConf['MAIL_PHPMailer']);
        }
    }
    /*
     * 加入发送队列
     * 如果有需要调用函数的话，函数需要在执行页面声明，否则不会自动调用。
     * @param $email                    需要送到的邮件
     * @param $name                     称呼
     * @param $title                    标题
     * @param $content                  内容/函数
     * @param $time                     发送时间的时间戳
     * @param null $for                 邮件的归属，一般是 项目代号+用户ID+用途 合并的字符串，通过这个字段可以删除未发送的 定时 邮件，就比如：LMS_1_notice 表示 LMS项目中uid=1的用户的提示邮件。
     * @param int $repeat               是否每天按照 发送时间 的时间点发送。
     * @param bool $is_function         邮件的内容是否是callback，如果 true 的话，将会以content为函数名，把邮件的所有数据以数组的形式传递。
     * @param callback $fail_callback   发送失败的回调函数名，如果不为空，则将该待发送所有字段以数组的形式传递，注：没有 is_function ,fail_callback等信息。
     * @return mixed                    添加发送成功或失败 true/false
     */
    function addEmailTimeQueue($email,$name,$title,$content,$time,$for=null,$repeat=0,$is_function=false,$fail_callback=null){
        if(empty($email) || empty($content))
            return false;

        if($time < time() && $repeat==0)
            return $this->addEmailQueue($email,$name,$title,$content,$for,$fail_callback);
        $arr = [
            'email'     =>  $email,
            'name'      =>  $name,
            'title'     =>  $title,
            'content'   =>  $content,
            'time'      =>  $time,
            'for'       =>  $for,
            'repeat'    =>  intval($repeat)%2,
            'is_function'   =>  intval($is_function)%2,
            'fail_callback' =>  $fail_callback,
        ];
        return $this->handle->addEmailTimeQueue($arr);
    }

    /**
     * 将邮件添加到待发送区（马上发送）
     * 参数含义与addEmailTimeQueue()相似，但出于安全性的考虑不能直接调用，仅供内部使用。
     * @param $email
     * @param $name
     * @param $title
     * @param $content
     * @param $for
     * @param $fail_callback
     * @return bool|int
     */
    private function addEmailQueue($email,$name,$title,$content,$for,$fail_callback){//这里的调用逻辑没有采用$arr，因为考虑到redis存储的时候将整个数组全json_encode，如果不经考虑直接传值，可能会出现异常。
        if(empty($email) || empty($content))
            return false;
        $arr = [
            'email' =>  $email,
            'name'  =>  $name,
            'title' =>  $title,
            'content'   =>  $content,
            'for'   =>  $for,
            'fail_callback' =>  $fail_callback,
        ];
        return $this->handle->addEmailQueue($arr);
    }
    /**
     * 解析并加载额外文件，通过字符传参的形式，仅限内部使用，已做避免重复加载的处理。
     * @param $str
     */
    private function includeExtFile($str){
        $arr = mb_split(',',$str);
        foreach($arr as $key => $value){
            if(file_exists($value) && !in_array($value,$this->loadedFile)) {
                $this->loadedFile[]=$value;
                include $value;
            }
        }
    }

    /**
     * 处理即将发送邮件的函数
     * @return array
     */
    public function dealEmailQueue(){
        $info = $this->mailConf;
        $data = array(
            'success'   =>  0,//发送成功的。
            'error'     =>  0,//发送失败的。
            'remove'    =>  0,//移除的。
            );

        //加载额外文件。
        $this->includeExtFile($this->mailConf['MAIL_ERR_EXTRA']);

        //通过驱动获取即将发送的邮件
        $emails = $this->handle->getEmailQueue($info['EMAIL_SEND_MAX']);

        $delEmails = [];

        //发送邮件并进行错误处理。
        foreach($emails as $arr){
            //错误次数过多，移除，并执行错误处理。
            if($arr['error_time'] >= 10){
                $delEmails[] = $arr;

                if(!empty($arr['fail_callback']) && function_exists($arr['fail_callback'])) {
                    $arr['time'] = time();
                    $arr['fail_callback']($arr);//调用callback，传入数据。
                }
                $data['remove']++;
                continue;
            }
            //发送成功或失败
            if($this->sendEmail($arr['email'],$arr['name'],$arr['title'],$arr['content']) === true) {
                $data['success']++;
                $delEmails[] = $arr;
                sleep($info['MAIL_SEND_SPACE']);//停留配置的时间s.
            }
            else{
                $data['error']++;
                $this->handle->errorTimePlus($arr);
            }
        }

        $this->handle->delEmailAll($delEmails);
        return $data;
    }

    public function dealEmailTimeQueue(){
        $info = $this->mailConf;

        //从驱动中获取即将处理的邮件。
        $emails = $this->handle->getEmailTimeQueue($info['EMAIL_TIME_MAX']);
        $data = array(
            'success'   =>  0,//发送成功的。
            'count'     =>  count($emails),//总邮件。
        );

        //加载额外的文件。
        $this->includeExtFile($this->mailConf['MAIL_CON_EXTRA']);

        //即将批量加入的数据
        $addData = [];
        //即将批量删除的数据
        $delData = [];
        //批量推迟的数据
        $delayData = [];

        //print_r($emails);

        //发送邮件并进行错误处理。
        foreach($emails as $arr){
            //检查$content是否是函数.
            if($arr['is_function']&&function_exists($arr['content'])){//这里主要是担心有用户写私信导致函数执行，repeat需要严格控制，最好限制只能是系统内部创建的。
                //$arr['time'] = time();//执行时间。 -- 这句话会导致无法正茬删除定时邮件
                $content=$arr['content']($arr);
            }else{
                $content=$arr['content'];
            }
            //处理重复发邮件的情况
            if($arr['repeat']) {
                $newTime = ($arr['time']+86400);

                $newTime = $newTime < time()?time()+86400-(time()-$newTime)%86400:$newTime;//避免短时间内重复发送大量同一邮件。

                echo time().' '.$newTime."\n";

                $arr['newTime'] = $newTime;
                $delayData[] = $arr;
            }
            else {//否则删除。
                $delData[] = $arr;
            }
            if(empty($content)) {
                continue;
            }
            //这里才更新content，不要放在前边去了，不然delData可能出错。
            $arr['content'] = $content;
            $addData[] = $arr;
            $data['success']++;
        }

        //print_r($delayData);

        //执行批量加入，批量删除
        $this->handle->addEmailAll($addData);
        $this->handle->delEmailTimeAll($delData);
        $this->handle->delayEmailTimeAll($delayData);
        return $data;
    }

    /**
     * 通过查找定时邮件队列中的for，删除邮件，可选最多删除个数。
     * @param $for      '查找的for
     * @param $limit    '最多删除个数。
     * @return mixed    '删除个数。
     */
    public function delEmailTimeQueue($for,$limit=null){
        $this->handle->delEmailTimeByFor($for,$limit);
    }
    /**
     * 正宗发送邮件，使用前，需要引入phpMailer。
     * @param $to
     * @param $name
     * @param $title
     * @param $content
     * @return bool
     */
    private function sendEmail($to,$name,$title,$content){
        $conf = $this->mailConf;

        $mail=new PHPMailer();
        $mail->IsSMTP(); // 启用SMTP
        $mail->Host=$conf['MAIL_HOST']; //smtp服务器的名称（这里以QQ邮箱为例）
        $mail->SMTPAuth = $conf['MAIL_SMTPAUTH']; //启用smtp认证
        $mail->Username = $conf['MAIL_USERNAME']; //你的邮箱名
        $mail->Password = $conf['MAIL_PASSWORD'] ; //邮箱密码
        $mail->From = $conf['MAIL_FROM']; //发件人地址（也就是你的邮箱地址）
        $mail->FromName = $conf['MAIL_FROM_NAME']; //发件人姓名
        $mail->AddAddress($to,$name);
        $mail->WordWrap = 50; //设置每行字符长度
        $mail->IsHTML($conf['MAIL_IS_HTML']); // 是否HTML格式邮件
        $mail->CharSet=$conf['MAIL_CHARSET']; //设置邮件编码
        $mail->Subject =$title; //邮件主题
        $mail->Body = $content; //邮件内容
        $mail->AltBody = $conf['ALT_BODY']; //邮件正文不支持HTML的备用显示
        if($mail->Send())
            return true;
        else{
            echo $mail->ErrorInfo."<br>\n";//输出错误信息。
            return false;
        }
    }
}
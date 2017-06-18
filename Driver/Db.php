<?php

namespace wq_email\dev;
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2017/6/15
 * Time: 14:56
 */
class Db
{
    private $conf = array(
        'type'      =>  'mysql',    //数据库类型。
        'host'      =>  '',         //数据库地址
        'username'  =>  '',         //数据库用户名
        'pwd'       =>  '',         //数据库密码
        'db_name'   =>  '',         //数据库名字
    );
    private $pdo;//pdo对象
    function __construct($conf)
    {
        $conf = $this->conf = array_merge($this->conf,$conf);//合并配置

        //创建PDO对象，使用时用prepare方法，将数据过滤放到MySql自己过滤。
        try {
            //预先转义 false，增加安全性，PHP版本最好在5.3.8以上，否则某些bug会危及数据库安全。
            $this->pdo = new \PDO($conf['type'] . ':host=' . $conf['host'] . ';dbname=' . $conf['db_name'].';charset=utf8;', $conf['username'], $conf['pwd'],array(\PDO::ATTR_PERSISTENT => true,\PDO::ATTR_EMULATE_PREPARES => false));
        }catch (\PDOException $e){
            die('Error:'.$e->getMessage());
        }
    }

    /**
     * 邮件驱动实现添加邮件队列，参数意义与上层一样。
     * @param $arr
     * @return bool
     */
    function addEmailTimeQueue($arr){
        $pdo = $this->pdo;

        $data = array(
            ':email'    =>  $arr['email'],
            ':title'    =>  $arr['title'],
            ':name'     =>  $arr['name'],
            ':content'  =>  $arr['content'],
            ':time'     =>  $arr['time'],
            ':repeat'   =>  $arr['repeat'],
            ':for'      =>  $arr['for'],
            ':is_function'=> $arr['is_function'],
            ':fail_callback'=> $arr['fail_callback'],
        );

        //添加入数据库。
        $st=$pdo->prepare("INSERT INTO wq_email_time(`email`, `title`, `name`, `content`, `send_time`,`repeat`, `for`, `is_function`, `fail_callback`)
                    VALUES(:email,:title,:name,:content,:time,:repeat,:for,:is_function,:fail_callback);") or die('add'.print_r($pdo->errorInfo()));
        return $st->execute($data);
    }

    /**
     * 添加入即将发送的邮件队列，参数含义与MyEmail.class.php中的同名函数相同。
     * @param $arr
     * @return bool
     */
    function addEmailQueue($arr){
        $pdo = $this->pdo;

        //添加入数据库。
        $st = $pdo->prepare("INSERT INTO wq_email(`email`, `title`, `name`, `content`, `for`, `fail_callback`,`error_time`)
                    VALUES(:email,:title,:name,:content,:for,:fail_callback,0)") or die(print_r($pdo->errorinfo()));
        $data = array(
            ':email'    =>  $arr['email'],
            ':title'    =>  $arr['title'],
            ':name'     =>  $arr['name'],
            ':content'  =>  $arr['content'],
            ':for'      =>  $arr['for'],
            ':fail_callback'=> $arr['fail_callback'],
        );
        return $st->execute($data);
    }

    /**
     * 获取即将发送的邮件
     * @param $limit
     * @return int|array
     */
    function getEmailQueue($limit){
        $pdo = $this->pdo;
        //从数据库获取邮件。
        $stmt = $pdo->prepare("SELECT * FROM wq_email LIMIT ?");
        return $stmt->execute([$limit])?$stmt->fetchAll():false;//获取数据
    }
    /**
     * 获取即将需要发送送队列的邮件
     * @param $limit
     * @return int|array
     */
    function getEmailTimeQueue($limit){
        $pdo = $this->pdo;
        //从数据库获取邮件。
        $stmt = $pdo->prepare("SELECT * FROM wq_email_time WHERE send_time<".time()." LIMIT ?");
        return $stmt->execute([$limit])?$stmt->fetchAll():false;//获取数据
    }

    /**
     * 错误次数加一
     * @param $arr
     * @return bool
     */
    function errorTimePlus($arr){
        $stm = $this->pdo->prepare("UPDATE wq_email SET error_time=error_time+1 WHERE id=? LIMIT 1;");
        return $stm->execute([$arr['id']]);

    }

    /**
     * 批量添加邮件
     * @param $data
     * @return int
     */
    function addEmailAll($data){
        //添加入即时发送队列的SQL.
        $stm = $this->pdo->prepare("INSERT INTO wq_email(`email`, `title`, `name`, `content`, `for`, `fail_callback`,`error_time`)
                    VALUES(:email,:title,:name,:content,:for,:fail_callback,0)");
        $count = 0;
        foreach ($data as $arr){
            $tmp = array(
                ':email'    =>  $arr['email'],
                ':title'    =>  $arr['title'],
                ':name'     =>  $arr['name'],
                ':content'  =>  $arr['content'],
                ':for'      =>  $arr['for'],
                ':fail_callback'=> $arr['fail_callback'],
            );
            if($stm->execute($tmp))
                $count++;
        }
        return $count;
    }

    /**
     * 批量删除邮件队列中的数据
     * @param $data
     * @return int
     */
    function delEmailTimeAll($data){
        //删除定时邮件的SQL prepare
        $stm = $this->pdo->prepare("DELETE FROM wq_email_time WHERE id=:id");
        $count = 0;
        foreach ($data as $arr){
            $tmp = array(
                ':id'   =>  $arr['id'],
            );
            if($stm->execute($tmp))
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
        $stm = $this->pdo->prepare("UPDATE wq_email_time SET send_time = ? WHERE id=? LIMIT 1;");
        $count = 0;
        foreach ($data as $arr){
            if($stm->execute([$arr['newTime'],$arr['id']]))
                $count++;
        }
        return $count;
    }

    /**
     * 删除待发送区的邮件，仅限内部使用。
     * @param $data
     * @return int
     */
    function delEmailAll($data){
        //删除定时邮件的SQL prepare
        $stm = $this->pdo->prepare("DELETE FROM wq_email WHERE id=:id");
        $count = 0;
        foreach ($data as $arr){
            $tmp = array(
                ':id'   =>  $arr['id'],
            );
            if($stm->execute($tmp))
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
        $pdo = $this->pdo;
        $limit = intval($limit);
        if($limit > 0)
            $st = $pdo->prepare("DELETE FROM wq_email_time WHERE `for`=:for LIMIT {$limit}");
        else
            $st = $pdo->prepare("DELETE FROM wq_email_time WHERE `for`=:for");
        return $st->execute(array(':for'=>$for));
    }
}
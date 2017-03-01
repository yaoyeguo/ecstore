<?php
/**
 * ShopEx licence
 *
 * @copyright  Copyright (c) 2005-2013 ShopEx Technologies Inc. (http://www.shopex.cn)
 * @license  http://ecos.shopex.cn/ ShopEx License
 */

class b2c_ctl_wap_comment extends wap_frontpage{

    var $noCache = true;
    function __construct(&$app){
         parent::__construct($app);
    }

    /*
     * 评论咨询和回复证码验证
     *
     * @access private
     * @params string $item 验证的类型
     * @params array  $_POST 验证码POST的值
     * @return bool
     * */
    private function _check_vcode($item){
        if( $this->app->getConf('comment.verifyCode') !="on" ){
            return true;;
        }

        $flag = true;
        switch($item){
            case 'ask':
                if(!base_vcode::verify('ASKVCODE',$_POST['askverifyCode'])){
                    $flag = false;
                }
                break;
            case 'discuss':
                if(!base_vcode::verify('DISSVCODE',$_POST['discussverifyCode'])){
                    $flag = false;
                }
                break;
            case 'reply':
                if(!base_vcode::verify('REPLYVCODE',$_POST['replyverifyCode'])){
                    $flag = false;
                }
                break;
        }
        if(!$flag){
            $this->splash('failed','',app::get('b2c')->_('验证码填写错误'),'','',true);
        }
        return $flag;
    }//End Function _check_vcode

    /*
        过滤POST来的数据,基于安全考虑,会把POST数组中带HTML标签的字符过滤掉
    */
    function check_input($data){
        $aData = $this->arrContentReplace($data);
        return $aData;
    }

    function arrContentReplace($array){
        if (is_array($array)){
            foreach($array as $key=>$v){
                $array[$key] = $this->arrContentReplace($array[$key]);
            }
        }else{
            $array = strip_tags($array);
        }
        return $array;
    }

    /*
     * 咨询评论提交的数据验证
     * @params array $_POST post和get提交的数据
     * @params string $type 类型discuss(评论)|ask（咨询）
     * @return bool
     * */
    private function _check_post($type){
        $_POST = $this->check_input($_POST);
        //验证基本参数
        if(!$_POST['goods_id']){
            $this->splash('failed',null,app::get('b2c')->_('参数错误'),'','',true);
        }else{
            $goodsData= $this->app->model('goods')->getList('goods_id',array('goods_id'=>$_POST['goods_id']));
            if(!$goodsData){
                $this->splash('failed',null,app::get('b2c')->_('参数错误'),'','',true);
            }
        }
        if($type == 'discuss' && (!$_POST['product_id'] || !$_POST['order_id'] || !$this->check_login()) ){
            $this->splash('failed',null,app::get('b2c')->_('参数错误'),'','',true);
        }
        if(empty($_POST['comment'])){
            $this->splash('failed',null,app::get('b2c')->_('内容不能为空'),'','',true);
        }

        //验证评论权限
        $disask = kernel::single('b2c_message_disask');
        if(!$disask->toValidate($type,$_POST,$message) ){
            if($message){
                $this->splash('failed',null,$message,'','',true);
            }else{
                $this->splash('failed',null,app::get('b2c')->_('权限不足'),'','',true);
            }
        }
        //验证码验证
        $this->_check_vcode($type);

        return true;
    }

    /*
     * 发表评论/咨询
     * */
    public function toComment($item='discuss'){
        if(!$this->check_login()){
            $url = $this->gen_url(array('app'=>'b2c','ctl'=>'wap_passport','act'=>'login'));
            $this->splash('success',$url,'没有登录，请登录!','','',true);exit;
        }
        $this->_check_post($item);

        $member_data = $this->get_current_member();
        $aData['hidden_name'] = $_POST['hidden_name'];
        $aData['gask_type'] = $_POST['gask_type'];
        $aData['goods_point'] = $_POST['point_type'];
        $aData['title'] = $_POST['title'];
        $aData['comment'] = $_POST['comment'];
        $aData['goods_id'] = $_POST['goods_id'];
        $aData['product_id'] = $_POST['product_id'];
        $aData['order_id'] = $_POST['order_id'];
        $aData['object_type'] = $item;
        $aData['author_id'] = $member_data['member_id'] ? $member_data['member_id']:0;
        $aData['author'] = ($member_data['uname'] ? $member_data['uname'] : app::get('b2c')->_('佚名'));
        $aData['contact'] = ($_POST['contact']=='' ? $member_data['email'] : $_POST['contact']);
        $aData['time'] = time();
        $aData['lastreply'] = 0;
        $aData['ip'] = $_SERVER["REMOTE_ADDR"];
        $aData['display'] = ($this->app->getConf('comment.display')=='soon' ? 'true' : 'false');

        //更新goods表,统计此商品评论，咨询的数量
        $objGoods = $this->app->model('goods');
        $objGoods->updateRank($_POST['goods_id'], $item,1);

        $objComment = kernel::single('b2c_message_disask');
        if($comment_id = $objComment->send($aData, $item, $message)){
            $comment_display = $this->app->getConf('comment.display');
            if($comment_display == 'soon' && $item == 'discuss' && $aData['author_id']){
                $_is_add_point = app::get('b2c')->getConf('member_point');
                if($_is_add_point){
                    $obj_member_point = $this->app->model('member_point');
                    $obj_member_point->change_point($aData['author_id'],$_is_add_point,$_msg,'comment_discuss',2,$aData['goods_id'],$aData['author_id'],'comment');
                }
            }

            $setting_display = $comment_display ? $comment_display : 'reply';
            if($setting_display == 'soon'){
                $message = $this->app->getConf('comment.submit_display_notice.'.$item);
            }else{
                $message = $this->app->getConf('comment.submit_hidden_notice.'.$item);
            }

            if($item == 'ask'){
                $this->ask_content_data($_POST['goods_id'],$type_id,'tab');
                $this->pagedata['comments']['gask_type'] = $objComment->gask_type($_POST['goods_id']);
                // $data = $this->fetch('wap/product/tab/ask/content.html');
                $url = $this->gen_url(array('app'=>'b2c','ctl'=>'wap_product','act'=>'index', 'arg0'=>$_POST['product_id']));
            }else{
                $url = $this->gen_url(array('app'=>'b2c','ctl'=>'wap_member','act'=>'nodiscuss'));
            }
            $message = $message ? $message : app::get('b2c')->_('发表成功');
            $this->splash('success',$url,$message,'','',true);
        }
        else{
            $this->splash('success',null,app::get('b2c')->_('发表失败'),'','',true);
        }
    }

    //客户回复评论
    function toReply(){
        $comment_id = $_POST['id'];
        if(!$comment_id) {
            $this->splash('error',null,app::get('b2c')->_('参数错误'),'','',true);
        }
        $member_data = $this->get_current_member();
        $objComment = kernel::single('b2c_message_disask');
        $aComment = $objComment->getList('*',array('comment_id'=>$comment_id));
        $aComment = $aComment[0];
        if(!$aComment){
            $this->splash('error',null,app::get('b2c')->_('记录为空'),'','',true);
        }

        //检查验证码
        $this->_check_vcode('reply');

        //检查回复权限
        $item = ($aComment['object_type'] == 'discuss') ? 'discussReply' : 'askReply';
        if(!$objComment->toValidate($item, $aComment['goods_id'], $member_data, $message)){
            $this->splash('error',null,$message,'','',true);
        }

        $aData['comment'] = $_POST['comment'];
        $aData['hidden_name'] = $_POST['hidden_name'];
        $aData['type_id'] = $aComment['type_id'];
        $aData['for_comment_id'] = $comment_id;
        $aData['author_id'] = $member_data['member_id'] ? $member_data['member_id']:0;
        $aData['mem_read_status'] = ($member_data['member_id']==$aComment['author_id'] ? 'true' : 'false');
        $aData['object_type'] = $aComment['object_type'];
        $aData['author'] = ($member_data['uname'] ? $member_data['uname'] : app::get('b2c')->_('佚名'));
        $aData['contact'] = ($_POST['contact']=='' ? $member_data['email'] : $_POST['contact']);
        $aData['to_id'] = $aComment['author_id'];
        $aData['time'] = time();
        $aData['lastreply'] = time();
        $aData['reply_name'] = $aData['author'];
        $aData['display'] = ($this->app->getConf('comment.display')=='soon' ? 'true' : 'false');
        if($objComment->send($aData,$aComment['object_type'])){
            $aData['goods_name'] = $aComment['goods_name'];
            $aData['goods_id'] = $aComment['goods_id'];
            $aData['uname'] = $aComment['author'];
            $comments = $this->app->model('member_comments');
            if($aComment['object_type'] == 'discuss') {
                $comments->fireEvent('discussreply',$aData,$aData['author_id']);
            }elseif($aComment['object_type']=='ask'){
                $comments->fireEvent('gaskreply',$aData,$aData['author_id']);
            }
            $return_data = $this->ajax_reply($aComment['object_type'],$comment_id);
            $setting_display = $this->app->getConf('comment.display') ? $this->app->getConf('comment.display'): 'reply';
            if($setting_display == 'soon'){
                $message = $this->app->getConf('comment.submit_display_notice.'.$aComment['object_type']);
            }else{
                $message = $this->app->getConf('comment.submit_hidden_notice.'.$aComment['object_type']);
            }
            $message = $message ? $message : app::get('b2c')->_('发表成功');
            $url = $this->gen_url(array('app'=>'b2c','ctl'=>'wap_product','act'=>'index','arg'=>$_POST['product_id']));
            $this->splash('success',$url,$message,'','',true);
        }else{
            $this->splash('failed',null,app::get('b2c')->_('发表失败'),'','',true);
        }
    }

    public function ask_content_data($gid,$pid,$type_id=null,$page_type,$page=1){
        if(isset($page_type) && $page_type == 'tab'){
            $this->pagedata['page_type'] = 'tab';
            $limit = 10;
        }
        if(!$gid) exit;
        $objdisask = kernel::single('b2c_message_disask');
        $aComment = $objdisask->good_all_disask($gid,'ask',$page,$type_id,$limit);
        $this->pagedata['comments'] = $aComment;
        $type_id = $type_id ? $type_id : 'all';
        $this->pagedata['pager'] = array(
            'current'=> $this->pagedata['comments']['askcurrent'],
            'total'=> $this->pagedata['comments']['asktotalpage'],
            'link'=>  $this->gen_url( array('app'=>'b2c','ctl'=>'wap_comment',
            'act'=>'ajax_ask','args'=>array($gid,$pid,$type_id,($tmp = time())))),
            'token'=>$tmp
        );
        $this->pagedata['goods_id'] = $gid;
        $this->pagedata['product_id'] = $pid;
    }

    //咨询类型ajax请求地址
    public function ajax_type_ask($gid,$pid,$type_id,$page_type){
        $this->ask_content_data($gid,$pid,$type_id,$page_type,$page);
        if(isset($page_type) && $page_type == 'init'){
            $this->pagedata['page_type'] = 'init';
        }
        echo $this->display('wap/product/tab/ask/list.html');
        exit;
    }

    //咨询翻页
    public function ajax_ask($gid,$pid,$type_id,$page){
        $type_id = ($type_id == 'all') ? null : $type_id;
        $this->ask_content_data($gid,$pid,$type_id,'tab',$page);
        echo $this->display('wap/product/tab/ask/list.html');
        exit;
    }

    //评论翻页
     public function ajax_discuss($gid,$page){
        if(!$gid or !$page) exit;
        $objdisask = kernel::single('b2c_message_disask');
        $aComment = $objdisask->good_all_disask($gid,'discuss',$page,null,10);
        $this->pagedata['comments'] = $aComment;
        $this->pagedata['goods_id'] = $gid;
        $this->pagedata['page_type'] = 'tab';
        $this->pagedata['pager'] = array(
            'current'=> $this->pagedata['comments']['discusscurrent'],
            'total'=> $this->pagedata['comments']['discusstotalpage'],
            'link'=>  $this->gen_url( array('app'=>'b2c','ctl'=>'wap_comment',
            'act'=>'ajax_discuss','args'=>array($gid,($tmp = time())))),
            'token'=>$tmp
        );
        echo $this->display('wap/product/tab/discuss/content.html');
        exit;
    }

    //评论咨询的回复数据
    public function ajax_reply($type,$comment_id){
        if(!$comment_id) {
            $this->splash('error',null,app::get('b2c')->_('参数错误'),true);
        }
        $objComment = kernel::single('b2c_message_disask');
        $objComment->type = $type;
        $aComment = $objComment->dump($comment_id);
        if(!$aComment){
            $this->splash('error',null,app::get('b2c')->_('记录为空'),true);
        }
        $aComment['items'] = $objComment->get_reply($comment_id);
        $this->pagedata['comlist'] = $aComment;
        //return $this->fetch('wap/product/tab/'.$type.'/reply.html');
    }
    /*
     *将评论咨询回复的未读设为已读
     * */
    public function readReply(){
        $comment_id = $_POST['comment_id'];
        $member_data = $this->get_current_member();
        if(!$comment_id || !$member_data['member_id'] ) {
            $this->splash('error',null,app::get('b2c')->_('参数错误'),true);
        }
        $objComment = app::get('b2c')->model('member_comments');
        $data = $objComment->getList('comment_id',array('comment_id'=>$comment_id,'author_id'=>$member_data['member_id']));
        if(!$data){
            $this->splash('error',null,app::get('b2c')->_('记录为空'),true);
        }
        $aComment = $objComment->update(array('mem_read_status'=>'true'),array('for_comment_id'=>$comment_id));
        if(!$aComment){
            $this->splash('error',null,app::get('b2c')->_('更新失败'),true);
        }else{
            $this->splash('success',null,app::get('b2c')->_('已读'),true);
        }
    }

}

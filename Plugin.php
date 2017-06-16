<?php
/**
 * 将 Typecho 的附件上传至七牛云存储中。
 * 
 * @package QiniuFile
 * @author abelyao
 * @version 1.3.1
 * @link http://www.abelyao.com/
 * @date 2017-02-16
 */

class QiniuFile_Plugin implements Typecho_Plugin_Interface {
    public static function activate() {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array('QiniuFile_Plugin', 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array('QiniuFile_Plugin', 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array('QiniuFile_Plugin', 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array('QiniuFile_Plugin', 'attachmentHandle');
        return _t('插件已经激活，需先配置七牛的信息！');
    }
    public static function deactivate() {}
    public static function config(Typecho_Widget_Helper_Form $form) {
        $bucket = new Typecho_Widget_Helper_Form_Element_Text('bucket', null, null, _t('空间名称：'));
        $form->addInput($bucket->addRule('required', _t('“空间名称”不能为空！')));

        $accesskey = new Typecho_Widget_Helper_Form_Element_Text('accesskey', null, null, _t('AccessKey：'));
        $form->addInput($accesskey->addRule('required', _t('AccessKey 不能为空！')));

        $sercetkey = new Typecho_Widget_Helper_Form_Element_Text('sercetkey', null, null, _t('SecretKey：'));
        $form->addInput($sercetkey->addRule('required', _t('SecretKey 不能为空！')));

        $domain = new Typecho_Widget_Helper_Form_Element_Text('domain', null, 'http://', _t('绑定域名：'), _t('以 http:// 开头，结尾不要加 / ！'));
        $form->addInput($domain->addRule('required', _t('请填写空间绑定的域名！'))->addRule('url', _t('您输入的域名格式错误！')));

        $savepath = new Typecho_Widget_Helper_Form_Element_Text('savepath', null, '{year}/{month}/', _t('保存路径格式：'), _t('附件保存路径的格式，默认为 Typecho 的 {year}/{month}/ 格式，注意<strong style="color:#C33;">前面不要加 / </strong>！<br />可选参数：{year} 年份、{month} 月份、{day} 日期'));
        $form->addInput($savepath->addRule('required', _t('请填写保存路径格式！')));

        $imgview = new Typecho_Widget_Helper_Form_Element_Radio('imgview',
            array('1' => _t('启用'),
                '0' => _t('禁止'),
            ),
            '0', _t('图片样式设置'), _t('默认禁止，启用后填写图片样式参数'));
        $form->addInput($imgview);

        $imgparam = new Typecho_Widget_Helper_Form_Element_Text('imgparam', null, null, '图片样式参数', '默认为空，七牛空间中设置的图片样式参数，以imageMogr2/auto-orient/thumbnail/开头的');
        $form->addInput($imgparam);
    }
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}
    // 获得插件配置信息
    public static function getConfig() {
        return Typecho_Widget::widget('Widget_Options')->plugin('QiniuFile');
    }
    // 旧版SDK调用(php5.2可用)
    public static function initSDK($accesskey, $sercetkey)
    {
        require_once 'sdk/io.php';
        require_once 'sdk/rs.php';
        Qiniu_SetKeys($accesskey, $sercetkey);
    }
    // 新版SDK调用(php5.3-7.0可用)
    public static function initAuto($accesskey, $sercetkey) {
        require_once('autoload.php');
        return new Qiniu\Auth($accesskey, $sercetkey);
    }
    // 删除文件
    public static function deleteFile($filepath) {
        // 获取插件配置
        $option = self::getConfig();

        // 旧版SDK删除(php5.2可用)
        // self::initSDK($option->accesskey, $option->sercetkey);
        // $client = new Qiniu_MacHttpClient(null);
        // return Qiniu_RS_Delete($client, $option->bucket, $filepath);

        // 新版SDK初始化(php5.3-7.0可用)
        $qiniu = self::initAuto($option->accesskey, $option->sercetkey);
        // 新版删除
        $bucketMgr = new Qiniu\Storage\BucketManager($qiniu);
        return $bucketMgr->delete($option->bucket, $filepath);
    }
    // 上传文件
    public static function uploadFile($file, $content = null) {
        // 获取上传文件
        if (empty($file['name'])) return false;

        // 校验扩展名
        $part = explode('.', $file['name']);
        $ext = (($length = count($part)) > 1) ? strtolower($part[$length-1]) : '';
        if (!Widget_Upload::checkFileType($ext)) return false;

        // 获取插件配置
        $option = self::getConfig();
        $date = new Typecho_Date(Typecho_Widget::widget('Widget_Options')->gmtTime);

        // 保存位置
        $savepath = preg_replace(array('/\{year\}/', '/\{month\}/', '/\{day\}/'), array($date->year, $date->month, $date->day), $option->savepath);
        $savename = $savepath . sprintf('%u', crc32(uniqid())) . '.' . $ext;
        if (isset($content))
        {
            $savename = $content['attachment']->path;
            self::deleteFile($savename);
        }

        // 上传文件
        $filename = $file['tmp_name'];
        if (!isset($filename)) return false;

        // 旧版SDK上传(php5.2可用)
        // self::initSDK($option->accesskey, $option->sercetkey);
        // $policy = new Qiniu_RS_PutPolicy($option->bucket);
        // $token = $policy->Token(null);
        // $extra = new Qiniu_PutExtra();
        // $extra->Crc32 = 1;
        // list($result, $error) = Qiniu_PutFile($token, $savename, $filename, $extra);$qiniu = self::qiniuset($settings->qiniuak,$settings->qiniusk);

        // 新版SDK初始化(php5.3-7.0可用)
        $token = self::initAuto($option->accesskey, $option->sercetkey)->uploadToken($option->bucket);
        // 新版上传
        $uploadMgr = new Qiniu\Storage\UploadManager();
        list($result, $error) = $uploadMgr->putFile($token, $savename, $filename);

        if ($error == null)
        {
            return array
            (
                'name'  =>  $file['name'],
                'path'  =>  $savename,
                'size'  =>  $file['size'],
                'type'  =>  $ext,
                'mime'  =>  Typecho_Common::mimeContentType($filename) // fix php5.6 requires absolute path
            );
        }
        else return false;
    }
    // 上传文件处理函数
    public static function uploadHandle($file) {
        return self::uploadFile($file);
    }
    // 修改文件处理函数
    public static function modifyHandle($content, $file) {
        return self::uploadFile($file, $content);
    }
    // 删除文件处理函数
    public static function deleteHandle(array $content) {
        self::deleteFile($content['attachment']->path);
    }
    // 获取实际文件绝对访问路径
    public static function attachmentHandle(array $content) {
        // $option = self::getConfig();
        $option = self::getConfig();
        $view = '';

        if($option->imgview > 0)
        {

            $view = '?'.($option->imgparam);
        }

        return Typecho_Common::url($content['attachment']->path, $option->domain).($view);
    }
}
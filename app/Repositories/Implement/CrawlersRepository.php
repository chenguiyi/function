<?php

namespace App\Repositories\Implement;
use App\Repositories\Interfaces\CrawlersInterface;
use App\Repositories\Common\Common;
use Illuminate\Support\Facades\DB;
use App\Repositories\Common\Math_BigInteger;
use App\Repositories\Common\CloudMusicApi;
/**
 * 数据收集
 *
 * @author CGY
 */
class CrawlersRepository extends Common implements CrawlersInterface{

    /**
     * 记录处理的数据
     */
    protected $executeNum = 0;

    //存储网易云音乐信息的hash前缀
    protected $cloudMusicMessageHashKeyPrefix = 'cloudMusicMessage_10000comment_';

    protected $CloudMusicApi;

    const COLUDDMIAN = 'http://music.163.com';

    public function __construct(){
        //实例化网易云音乐api类
        $this->CloudMusicApi = new CloudMusicApi();
    }
    /**
     * 获取网易云的歌单分类并插入到数据库
     * @return void
     */
    public function getCategoryList(){

        //网易云歌单首页
        $url = "http://music.163.com/discover/playlist/?order=hot";
        $CategoryPage = $this->sendCurl($url);

        //匹配一级分类及包含的二级分类区域
        $rule                 = '#<dt><i class="u-icn u-.*"></i>(.*)</dt>([\s\S]*?)</dd>#';
        $list                 = $this->pregMathAll($rule,$CategoryPage);
        //歌单的第一级分类
        $firstCategoryList    = $list[0];
        //包含第二级分类的字符串
        $secondCategoryString = $list[1];   
        //存放分类的数组    
        $categoryList         = array();
        //简单判断一下总的分类数量 便于判定是否要更新数据库
        $totalCateNum         = count($firstCategoryList);
        //获取第二级分类 将第一级和第二级对应起来
        foreach ($secondCategoryString as $key => $value) {

            if(!isset($categoryList[$key]['firstCategory'])){
                $categoryList[$key]['firstCategory'] = $firstCategoryList[$key];
            }
            //二级分类抓取
            $rule   = '|<a class="s-fc1 " href="(.*)" data-cat="(.*)">.*</a><span class="line">|';
            $result = $this->pregMathAll($rule,$value);
            if(!empty($result)){
                //链接地址
                $hrefList  = $result[0];
                //分类标题
                $titleList = $result[1];

                foreach ($hrefList as $k => $v) {
                    //获得的第二级分类名称和对应的URL
                    $newList[$k]['href']     = self::COLUDDMIAN . $v;
                    $newList[$k]['cateName'] = $titleList[$k]; 
                }
                //第二级的分类也算进去
                $totalCateNum += count($newList);
            }
            $categoryList[$key]['secondCategory'] = $newList;
        }
        //数据表模型
        $model = new \App\Models\CloudMusicCategory;
        //数据表中存在的数量
        $count = $model->count();

        //抓取出来的数量大于数据表中已有的就重新生成
        if(intval($count) < intval($totalCateNum)){
            
            $totalError = array();
            //清空一下数据表
            $model->truncate();
            //插入数据库
            foreach($categoryList as $key=>$value){
                //获取模型
                
                $data = array('cateName'=>$value['firstCategory']);
                $res = $model->create($data);

                if(empty($res)){
                    $totalError[] = array('cateName'=>$value['firstCategory'],);
                }else{
                    $cateId = $res->cateId;
                    $cateName = $res->cateName;
                    foreach ($value['secondCategory'] as $k => $v) {
                       $data = array('cateName'       => $v['cateName'],
                                     'parentCateId'   => $cateId,
                                     'parentCateName' => $cateName,
                                     'link'           => $v['href']);
                       $res = $model->create($data);
                       $this->executeNum++;
                    }
                }
            }
            return array('totalSuccessNum'=>$this->executeNum,'totalError'=>$totalError);
        }
        return array('msg'=>'the category data is existed,you don\'t need to collect again');
    }

    /**
     * 歌单抓取
     * @return array
     */
    public function getPlayList(){
        echo $this->Redis()->get('ss');die;
        //分类表模型
        $CateModel = new \App\Models\CloudMusicCategory;
        $list = $CateModel->where('parentCateId','>',0)->orderBy('cateId','asc')->get()->toArray();

        if(empty($list)){
            //抓取分类
            $this->getCategoryList();
            $list = $CateModel->where('parentCateId','>',0)->orderBy('cateId','asc')->get()->toArray();
        }

        //歌单表模型
        $PlayListModel = new \App\Models\CloudPlayList;
        $executeNum = 0;

        //获取一下上次的抓取断点 继续上次的抓取
        $lastExecuteResult = DB::table('execute_result')->orderBy('id','desc')->first();
        //如果没数据 就是第一次
        if(!empty($lastExecuteResult)){
            $lastCate = $lastExecuteResult->cateId;
            $lastOffset = $lastExecuteResult->offset;
        }else{
            $lastOffset = 0;
        }
        //遍历分类列表 根据列表中的链接地址去抓取歌单
        foreach ($list as $value) {
        	//已经抓取过啦
            if(isset($lastCate) & !empty($lastCate)){
            	if($value['cateId'] < $lastCate){
            		continue;
            	}
            }
            //重置
            if($lastOffset > 0){
                $lastOffset = 0;
            }
            
        	//使用事务来批量插入
        	DB::beginTransaction();
        	//要插入的数据先存放在数组里面
        	$playList = array();
        	//id同一放数组里面 一起验证重复
            $playListId = array();

            $url = $value['link'];
            //每次获取35个歌单
            for($offset=$lastOffset,$limit=35;;$offset += 35){

                $data = array('limit'=>$limit,'offset'=>$offset);
                $newUrl = $url.'&'.http_build_query($data);
                $res = $this->sendCurl($newUrl);

                // $this->putIntoFile('crawler/playlist.txt',$res);die;
                //获取最后一页的页码
                if(!isset($lastPageNum)){
                    //没有分页抓取一次分页
                    $rule = '|<a href=".*" class="zpgi">(.*)<\/a>|';
                    $pageList = $this->pregMathAll($rule,$res)[0];
                    //最后一个分页
                    $lastPageNum = array_pop($pageList);
                }
                //判断抓取的是不是最后一页
                if(intval($offset) >= (intval($lastPageNum)-1)*35){
                    //先清空最后的页数
                    unset($lastPageNum);
                    //跳出循环
                    break;
                }

                //开始抓取
                $rule = '|<img class="j-flag" src="(.*)"\/>\n<a title="(.*)" href="(.*)" class="msk">[\s\S]*?data-res-id="(.*)"[\s\S]*?<span class="nb">(.*)<\/span>[\s\S]*?<a title=[\s\S]*?<a title="(.*?)" href="(.*?)"|';
                $pageList = $this->pregMathAll($rule,$res);
                // echo '<pre>';print_r($pageList);die;
                if(!empty($res)){
                    //歌单图片
                    $imgList   		= $pageList[0];
                    //歌单标题
                    $titleList 		= $pageList[1];
                    //歌单链接地址
                    $hrefList  		= $pageList[2];
                    //歌单Id
                    $listId 		= $pageList[3];
                    //收听数
                    $listenList = $pageList[4];
                    //歌单创建人
                    $byList   = $pageList[5];
                    //创建人空间链接
                    $spaceLinkList = $pageList[6];

                }
 				//数据先存储到数组中统一插入
                foreach ($listId as $k => $v) {

                    $data = array('listId'       => $v,
                                  'listTitle'    => $titleList[$k],
                                  'listImg'      => $imgList[$k],
                                  'link'         => self::COLUDDMIAN.$hrefList[$k],
                                  'listenNum'   => $this->chineseToNumber($listenList[$k]),
                                  'by'           => $byList[$k],
                                  'spaceLink'    => self::COLUDDMIAN.$spaceLinkList[$k],
                                  'parentCateId' => $value['cateId']);
                    //存放到数组里面 统一插入
                    $playList[$v] = $data;
                    //存放到数组里面 统一验重复数据
                    $playListId[] = $v;
                    //总共抓取的个数
                    $executeNum++;

                }
                //每隔四个页面随机暂停 说是为了不被当成恶意访问 
                if($executeNum % 35 == 0){
                    sleep(rand(1,5));
                }	

                //抓取的记录存入到txt文件中  只是为了断点不用重新抓取
                $executeData = array('cateId'=>$value['cateId'],'offset'=>$offset);
                DB::table('execute_result')->insert($executeData);  
            }

            //验证一下重复的数据
		    $list = $PlayListModel->select('listId')->whereIn('listId',$playListId)->get()->toArray();
		      
		    if(!empty($list)){
               	foreach ($list as $val) {
			        unset($playList[$val['listId']]);
			    }
			}
	        if(!empty($playList)){
		        foreach ($playList as $keys => $val){
		        	$createResult = $PlayListModel->create($val);
		        }
	        } 
	        //提交事务
	        DB::commit();	     
    	}
    }

    /**
     * 抓取歌曲信息
     *
     * 主要从歌单中抓取 
     * @return array
     */
    public function collectMusicMessage(){

        $Redis = $this->Redis();
        // echo $Redis->llen('playlist');die;
        // $Redis->del('playlist');
        // $Redis->del('lastPlayListId');
        //队列为空的话 添加url进队列
        if(empty($Redis->llen('playlist'))){
            $this->putUrlIntoQueue();
        }

        //右边出队列
        $url   = $Redis->rpop('playlist');
        //请求页面信息
        $res   = $this->sendCurl($url);
        //获取正则表达式
        $rule  = $this->getPregRule('musiclist');
        //正则匹配
        $array = $this->pregMathAll($rule,$res);

        $idArray = $array[0];

        //根据抓取的Id去抓取歌曲信息
        $this->getMusicMessage($idArray); 

        // $this->putIntoFile('crawler/musiclist.txt',$res);

    }

    /**
     * 添加url进队列
     * @return [type] [description]
     */
    public function putUrlIntoQueue(){

        $Redis = $this->Redis();

        $lastPlayListId = empty($Redis->get('lastPlayListId')) ? 0 : $Redis->get('lastPlayListId');
        // echo $lastPlayListId;die;
        // 歌单模型
        $PlayListModel = new \App\Models\CloudPlayList;
        
        //一次取10000条添加进队列
        for($offset = 0,$num=10000,$totalNum=0;;$offset=$offset+$num){
            //原来还有offset
            $playList = $PlayListModel->select('id','listId','link')->where('id','>',$lastPlayListId)
                                      ->orderBy('id','asc')->offset($offset)->limit($num)->get()
                                      ->toArray();

            if(empty($playList)){
                break;
            }
            // print_r($playList);
            $urlList  = array();
            foreach ($playList as $value) {
                $urlList[] = $value['link'];
            }
            // echo '<pre>';
            // print_r($urlList);
            //添加进去队列  左入列
            $totalNum = $this->putArrayIntoQueue($urlList,'playlist');

            //记录一下最后的一个Id  最后一次查找的到playList为空 需要上一次的
            $lastPlayListIdArray = array_pop($playList);

            $Redis->set('lastPlayListId',$lastPlayListId['id']);
        }

        return $totalNum;
    }

    public function getMusicMessage($musicIdArray){

        if(empty($musicIdArray)){
            return false;
        }

        $Redis = $this->Redis();
 
        //网易云音乐 音乐地址格式
        $musicLinkDomain  = self::COLUDDMIAN.'/song?id=';
        //匹配歌曲信息的正则表达式
        $musicMessageRule = $this->getPregRule('musicMessage');
        //匹配歌手信息的正则表达式
        $singerRule       = $this->getPregRule('singer');
        foreach ($musicIdArray as $musicId) {
            //把要抓取的音乐Id放入redis集合之中 集合可以自动排重
            $insertMsg = $Redis->set('musicIdList',$musicId);
            //返回为int(1)则为未抓取过的
            if($insertMsg){
                //抓取总评论数
                $musicCommentMsg = $this->CloudMusicApi->musicCommentMsg($musicId);
                if(!empty($musicCommentMsg)){
                    //获得的数据为一个json格式的字符串
                    $commentArray       = json_decode($musicCommentMsg);
                    //只要评论数大于10000的
                    $totalCommnetNum    = $commentArray->total;
                    if(intval($totalCommnetNum) > 10000){
                        //抓取歌曲信息
                        $musicMessagePage   = $this->CloudMusicApi->musicMessage($musicId);
                        //匹配歌曲信息
                        $musicMessageArray  = $this->pregMathAll($musicMessageRule,$musicMessagePage);

                        //歌唱者有可能会有多个合唱 进一步处理
                        $singerString       = $musicMessageArray[1][0];
                        $singerMessageArray = $this->pregMathAll($singerRule,$singerString);

                        //歌手有多个 存为一个JSON 
                        foreach ($singerMessageArray[1] as $key=>$singerName){
                            $array[$key]['singer']     = $singerName;
                            $array[$key]['singerLink'] = $singerMessageArray[0][$key];
                        }
                        $musicMsg['musicId']            = $musicId;
                        $musicMsg['singerMessage']      = json_encode($array);
                        //歌曲名
                        $musicMsg['musicTitle']         = $musicMessageArray[0][0];
                        //歌曲所属专辑链接
                        $musicMsg['musicAlbumLink']     = self::COLUDDMIAN.$musicMessageArray[2][0];
                        //歌曲所属专辑名称
                        $musicMsg['musicAlbumTitle']    = $musicMessageArray[3][0];
                        //存储到hash中
                        $this->putMusicMessageIntoRedisHash($musicMsg);
                        // $this->putIntoFile('crawler/musicMsg',$singerString);die;
                    }
                }
                // $this->putIntoFile('crawler/json.txt',$res);die;
            }
            
        }
    }

    /**
     * 用hash结构来存储抓取到的数据
     *
     * 先用hash来存储数据 而后通过指定时间或者指定抓取多少数据之后统一存储到数据库
     * 
     * @return string
     */
    protected function putMusicMessageIntoRedisHash($musicMsg){
        //键值自增
        $id = $this->Redis()->incr($this->cloudMusicMessageHashKeyPrefix.'keyNo');
        if($id){
            $this->Redis()->hmset($this->cloudMusicMessageHashKeyPrefix.$id,$musicMsg);
            //返回键值
            return $id;
        }
    }



}

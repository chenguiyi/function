<?php

namespace App\Models;

class FunctionList extends Common{
	//关联表
	protected $table      = 'function_list';
	//关联主键
	protected $primaryKey = 'id';
    //laravel可以自动维护时间 以下可关闭
    // protected $timestamps = false;
}

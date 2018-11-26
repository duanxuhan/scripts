#!/bin/bash
# 编译安装管理PHP
# yum -y install gcc pcre-devel libxml2-devel openssl-devel libcurl-devel libjpeg-devel libpng-devel freetype-devel libmcrypt-devel
App=php
AppName=PHP
AppBase=/App
AppDir=$AppBase/$App
AppProg=$AppDir/sbin/php-fpm
AppIni=$AppDir/etc/php.ini
AppConf=$AppDir/etc/php-fpm.conf
ExtensionDir=$($AppDir/bin/php-config --extension-dir)
CoreNum=$(cat /proc/cpuinfo | grep processor | wc -l)

AppSrcBase=/root/src
AppSrcFile=$App-*.tar.*
AppSrcDir=$(find $AppSrcBase -maxdepth 1 -name "$AppSrcFile" -type f 2> /dev/null | sed -e 's/.tar.*$//' -e 's/^.\///')
AppUser=$(grep "^[[:space:]]*user" $AppConf 2> /dev/null | awk -F= '{print $2}' | sed -e 's/[[:space:]]//g' -e 's/"//g' -e "s/'//g")
AppGroup=$(grep "^[[:space:]]*group" $AppConf 2> /dev/null | awk -F= '{print $2}' | sed -e 's/[[:space:]]//g' -e 's/"//g' -e "s/'//g")
AppPidDir=$(dirname $(grep "^[[:space:]]*pid" $AppConf 2> /dev/null | awk -F= '{print $2}' | sed -e 's/[[:space:]]//g' -e 's/"//g' -e "s/'//g") 2> /dev/null)
AppErrorLogDir=$(dirname $(grep "^[[:space:]]*error_log" $AppConf 2> /dev/null | awk -F= '{print $2}' | sed -e 's/[[:space:]]//g' -e 's/"//g' -e "s/'//g") 2> /dev/null)
AppSlowLogDir=$(dirname $(grep "^[[:space:]]*slowlog" $AppConf 2> /dev/null | awk -F= '{print $2}' | sed -e 's/[[:space:]]//g' -e 's/"//g' -e "s/'//g") 2> /dev/null)
UploadTmpDir=$(grep "^[[:space:]]*upload_tmp_dir" $AppIni 2> /dev/null | awk -F= '{print $2}' | sed -e 's/[[:space:]]//g' -e 's/"//g' -e "s/'//g")

AppUser=${AppUser:-nobody}
AppGroup=${AppGroup:-nobody}
AppPidDir=${AppPidDir:=$AppDir/var/run}
AppErrorLogDir=${AppErrorLogDir:-$AppDir/var/log}
AppSlowLogDir=${AppSlowLogDir:-$AppDir/var/log}

RemoveFlag=0
InstallFlag=0

ScriptDir=$(cd $(dirname $0); pwd)
ScriptFile=$(basename $0)

# 获取PID
Pid()
{
    AppMasterPid=$(ps ax | grep "php-fpm: master process" | grep -v "grep" | awk '{print $1}' 2> /dev/null)
    AppWorkerPid=$(ps ax | grep "php-fpm: pool" | grep -v "grep" | awk '{print $1}' 2> /dev/null)
}

# 安装
Install()
{
    Pid
    InstallFlag=1

    if [ -z "$AppMasterPid" ]; then
        test -f "$AppProg" && echo "$AppName 已安装"
        [ $? -ne 0 ] && Update && Conf
    else
        echo "$AppName 正在运行"
    fi
}

# 更新
Update()
{
    Operate="更新"
    [ $InstallFlag -eq 1 ] && Operate="安装"
    [ $RemoveFlag -ne 1 ] && Backup

    cd $AppSrcBase
    test -d "$AppSrcDir" && rm -rf $AppSrcDir

    tar Jxf $AppSrcFile || tar jxf $AppSrcFile || tar zxf $AppSrcFile
    cd $AppSrcDir

    ./configure \
    "--prefix=$AppDir" \
    "--disable-all" \
    "--enable-bcmath" \
    "--enable-calendar" \
    "--enable-ctype" \
    "--enable-dom" \
    "--enable-fileinfo" \
    "--enable-filter" \
    "--enable-fpm" \
    "--enable-ftp" \
    "--enable-gd-native-ttf" \
    "--enable-hash" \
    "--enable-json" \
    "--enable-libxml" \
    "--enable-mbstring" \
    "--enable-opcache" \
    "--enable-pdo" \
    "--enable-phar" \
    "--enable-posix" \
    "--enable-session" \
    "--enable-simplexml" \
    "--enable-soap" \
    "--enable-sockets" \
    "--enable-tokenizer" \
    "--enable-xml" \
    "--enable-xmlreader" \
    "--enable-xmlwriter" \
    "--enable-zip" \
    "--with-curl" \
    "--with-freetype-dir" \
    "--with-gd" \
    "--with-gettext" \
    "--with-iconv" \
    "--with-jpeg-dir" \
    "--with-mcrypt" \
    "--with-mysqli=mysqlnd" \
    "--with-openssl" \
    "--with-pcre-dir" \
    "--with-pdo-mysql=mysqlnd" \
    "--with-png-dir" \
    "--with-xmlrpc" \
    "--with-zlib" 

    [ $? -eq 0 ] && make ZEND_EXTRA_LIBS='-liconv' -j$CoreNum && make install
    if [ $? -eq 0 ];then
        echo "$AppName $Operate成功"
    else
        echo "$AppName $Operate失败"
        exit 1
    fi
}

# 重装
Reinstall()
{
    Remove && Install
}

# 删除
Remove()
{
    Pid
    RemoveFlag=1

    if [ -z "$AppMasterPid" ]; then
        if [ -d "$AppDir" ]; then
            rm -rf $AppDir && echo "删除 $AppName"
        else
            echo "$AppName 未安装"
        fi
    else
        echo "$AppName 正在运行" && exit
    fi
}

# 备份
Backup()
{
    Day=$(date +%Y-%m-%d)
    BackupFile=$App.$Day.tgz

    if [ -f "$AppProg" ]; then
        cd $AppBase
        tar zcvf $BackupFile --exclude=var/log/* --exclude=var/run/* $App --backup=numbered
        [ $? -eq 0 ] && echo "$AppName 备份成功" || echo "$AppName 备份失败"
    else
        echo "$AppName 未安装"
    fi
}

# 初始化
Init()
{
    echo "初始化 $AppName"

    groupadd $AppGroup && echo "新建 $AppName 运行组：$AppGroup"
    useradd -s /bin/false -g $AppGroup -M $AppUser && echo "新建 $AppName 运行用户：$AppUser"

    echo $AppPidDir | grep -q "^/"
    if [ $? -eq 1 ]; then
        AppPidDir=$AppDir/var/$AppPidDir
    fi

    if [ ! -e "$AppPidDir" ]; then
        mkdir -p $AppPidDir && echo "新建 $AppName PID文件存放目录：$AppPidDir"
    else
        echo "$AppName PID文件存放目录：$AppPidDir 已存在"
    fi

    echo $AppErrorLogDir | grep -q "^/"
    if [ $? -eq 1 ]; then
        AppErrorLogDir=$AppDir/var/$AppErrorLogDir
    fi

    if [ ! -e "$AppErrorLogDir" ]; then
        mkdir -p $AppErrorLogDir && echo "新建 $AppName 错误日志目录：$AppErrorLogDir"
    else
        echo "$AppErrorLogDir 错误日志目录：$AppErrorLogDir 已存在"
    fi

    echo $AppSlowLogDir | grep -q "^/"
    if [ $? -eq 1 ]; then
        AppSlowLogDir=$AppDir/$AppSlowLogDir
    fi

    if [ ! -e "$AppSlowLogDir" ]; then
        mkdir -p $AppSlowLogDir && echo "新建 $AppName 慢日志目录：$AppSlowLogDir"
    else
        echo "$AppSlowLogDir 慢日志目录：$AppSlowLogDir 已存在"
    fi
    printf "\n"

    if [ -n "$UploadTmpDir" ]; then
        echo $UploadTmpDir | grep -q "^/"
        if [ $? -eq 0 ]; then
            if [ ! -e "$UploadTmpDir" ]; then
                mkdir -p $UploadTmpDir && echo "新建 $AppName 文件上传临时存储目录：$UploadTmpDir"
            else
                echo "$AppName 文件上传临时存储目录：$UploadTmpDir 已存在"
            fi

            chown -R $AppUser:$AppGroup $UploadTmpDir && echo "修改 $AppName 文件上传临时存储目录拥有者为 $AppUser，属组为 $AppGroup"
            printf "\n"
        fi
    fi

    sed -i "s|extension_dir.*$|extension_dir = \"$ExtensionDir\"|" $AppIni
}

# 启动
Start()
{
    Pid

    if [ -n "$AppMasterPid" ]; then
        echo "$AppName 正在运行"
    else
        $AppProg -c $AppIni && echo "启动 $AppName" || echo "$AppName 启动失败"
    fi
}

# 停止
Stop()
{
    Pid

    if [ -n "$AppMasterPid" ]; then
        kill -INT $AppMasterPid && echo "停止 $AppName" || echo "$AppName 停止失败"
    else
        echo "$AppName 未启动"
    fi
}

# 重启
Restart()
{
    Pid
    [ -n "$AppMasterPid" ] && Stop && sleep 1
    Start
}

# 查询状态
Status()
{
    Pid

    if [ ! -f "$AppProg" ]; then
            echo "$AppName 未安装"
    else
        echo "$AppName 已安装"
        if [ -z "$AppMasterPid" ]; then
            echo "$AppName 未启动"
        else
            echo "$AppName 正在运行"
        fi
    fi
}

# 拷贝配置
Conf()
{
    cp -vf --backup=numbered $ScriptDir/php.ini $AppIni
    cp -vf --backup=numbered $ScriptDir/php-fpm.conf $AppConf
}

# 检查配置
Check()
{
    $AppProg -t && echo "$AppName 配置正确" || echo "$AppName 配置错误"
}

# 重载配置
Reload()
{
    Pid

    if [ -n "$AppMasterPid" ]; then
        kill -USR2 $AppMasterPid && echo "重载 $AppName 配置" || echo "$AppName 重载配置失败"
    else
        echo "$AppName 未启动"
    fi
}

# 终止进程
Kill()
{
    Pid

    if [ -n "$AppMasterPid" ]; then
        echo "$AppMasterPid" | xargs kill -9
        if [ $? -eq 0 ]; then
            echo "终止 $AppName 主进程"
        else
            echo "终止 $AppName 主进程失败"
        fi
    else
        echo "$AppName 主进程未运行"
    fi

    if [ -n "$AppWorkerPid" ]; then
        echo "$AppWorkerPid" | xargs kill -9
        if [ $? -eq 0 ]; then
            echo "终止 $AppName 工作进程"
        else
            echo "终止 $AppName 工作进程失败"
        fi
    else
        echo "$AppName 工作进程未运行"
    fi
}

case "$1" in
    "install"   ) Install;;
    "update"    ) Update;;
    "reinstall" ) Reinstall;;
    "remove"    ) Remove;;
    "backup"    ) Backup;;
    "init"      ) Init;;
    "start"     ) Start;;
    "stop"      ) Stop;;
    "restart"   ) Restart;;
    "status"    ) Status;;
    "conf"      ) Conf;;
    "check"     ) Check;;
    "reload"    ) Reload;;
    "kill"      ) Kill;;
    *           )
    echo "$ScriptFile install              安装 $AppName"
    echo "$ScriptFile update               更新 $AppName"
    echo "$ScriptFile reinstall            重装 $AppName"
    echo "$ScriptFile remove               删除 $AppName"
    echo "$ScriptFile backup               备份 $AppName"
    echo "$ScriptFile init                 初始化 $AppName"
    echo "$ScriptFile start                启动 $AppName"
    echo "$ScriptFile stop                 停止 $AppName"
    echo "$ScriptFile restart              重启 $AppName"
    echo "$ScriptFile status               查询 $AppName 状态"
    echo "$ScriptFile conf                 拷贝 $AppName 配置"
    echo "$ScriptFile check                检查 $AppName 配置"
    echo "$ScriptFile reload               重载 $AppName 配置"
    echo "$ScriptFile kill                 终止 $AppName 进程"
    ;;
esac

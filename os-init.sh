#!/bin/bash
# 服务优化
Services=(atd avahi-daemon cups dmraid-activation firewalld irqbalance kdump mdmonitor postfix)
for Service in ${Services[*]}
do
    systemctl disable ${Service}
    systemctl stop ${Service}
done
systemctl enable rc-local

# 内核参数调优
grep -q "^fs.file-max = 6815744" /etc/sysctl.conf || cat >> /etc/sysctl.conf << EOF
########################################
vm.swappiness = 0
vm.overcommit_memory = 1
net.core.rmem_default = 262144
net.core.rmem_max = 16777216
net.core.wmem_default = 262144
net.core.wmem_max = 16777216
net.core.somaxconn = 60000
net.core.netdev_max_backlog = 60000
net.ipv4.tcp_max_orphans = 60000
net.ipv4.tcp_orphan_retries = 3
net.ipv4.tcp_max_syn_backlog = 60000
net.ipv4.tcp_max_tw_buckets = 10000
net.ipv4.ip_local_port_range = 1024 65500
net.ipv4.tcp_tw_recycle = 1
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_synack_retries = 1
net.ipv4.tcp_syn_retries = 1
net.ipv4.tcp_fin_timeout = 30
net.ipv4.tcp_keepalive_time = 1200
net.ipv4.tcp_mem = 786432 1048576 1572864
fs.aio-max-nr = 1048576
fs.file-max = 6815744
kernel.sem = 250 32000 100 10000
kernel.pid_max = 65536
fs.inotify.max_user_watches = 1048576
kernel.kptr_restrict = 1
kernel.ctrl-alt-del = 1
EOF
sysctl -p

# 提高系统PAM认证登录用户打开文件数、打开进程数限制
grep -q "^* - nofile 1048576" /etc/security/limits.conf || cat >> /etc/security/limits.conf << EOF
########################################
* - nofile 1048576
* - nproc  65536
EOF

# 提高systemd服务的打开文件数、打开进程数限制
grep -q "^DefaultLimitNOFILE=1048576" /etc/systemd/system.conf || cat >> /etc/systemd/system.conf << EOF
########################################
DefaultLimitNOFILE=1048576
DefaultLimitNPROC=65536
EOF
systemctl daemon-reexec

# 提高Shell打开文件数、打开进程数限制
grep -q "^ulimit -n 1048576" /etc/profile || cat >> /etc/profile << EOF
########################################
ulimit -n 1048576
ulimit -u 65536

alias grep='grep --color=auto'
export HISTTIMEFORMAT="%Y-%m-%d %H:%M:%S "
export TMOUT=600
EOF

# 禁用并关闭selinux
sed -i 's/SELINUX=enforcing/SELINUX=disabled/' /etc/selinux/config
setenforce 0

# 优化SSH服务
sed -i 's/.*UseDNS yes/UseDNS no/' /etc/ssh/sshd_config
sed -i 's/.*GSSAPIAuthentication yes/GSSAPIAuthentication no/' /etc/ssh/sshd_config
systemctl restart sshd

# 脚本目录加入PATH环境变量
grep -q "^/root/script" $HOME/.bashrc || cat >> $HOME/.bashrc << EOF
########################################
export PATH=/root/script:\$PATH
EOF
mkdir -p /root/script /root/src /App

# 挂载tmpfs文件系统
mount --bind /dev/shm /tmp
grep -q "mount --bind /dev/shm /tmp" /etc/rc.local || echo "mount --bind /dev/shm /tmp" >> /etc/rc.local
chmod +x /etc/rc.d/rc.local
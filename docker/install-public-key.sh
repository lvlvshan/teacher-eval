# 在服务器上执行：把本机公钥写入 root 的 authorized_keys
# 用法：ssh root@192.168.139.129 后逐行执行，或保存为脚本执行
set -e

mkdir -p /root/.ssh
chmod 700 /root/.ssh

cat >> /root/.ssh/authorized_keys <<'EOF'
ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDdf8vQZPREXTjGYd+PgCb2EWQjm06ltlBTTHBHv5MhHXAVPUSg3PBwU51MFT+lLmS+/dxyIrY+GqJe/nd6XzsblLa6cajVcuVLd0H1H5SjUFR3KAztqwr6qToE+QSEEPj6evpnbK2HHCPFiYTCpJeAJMhdYqMiKl2AehqaVfmzDpn/dJVEKCLzTxlnGtzA2Ud6rPEm1qcA2ezf9TWpiNygFUKEW7uV43ABD7F4moXA+l3cEYm++s3Jb1H/jEZBZSFtoJc78IjfJvaZn63vUxJPdInKFEo3AK3codsWYlPAYFzkH5gJMJ3BX9D6nAfleJ9B+vcy1IYME/bV0VJIy0W5
EOF

chmod 600 /root/.ssh/authorized_keys

# CentOS 7 若开启 SELinux，修正上下文
restorecon -Rv /root/.ssh 2>/dev/null || true

# 确认 sshd 允许 root 用密钥登录
grep -E '^(PermitRootLogin|PubkeyAuthentication)' /etc/ssh/sshd_config || true
systemctl restart sshd 2>/dev/null || systemctl restart ssh 2>/dev/null || true

echo "done"

# 可选：在服务器上安装运维用的 SSH 公钥（仅在你需要密钥登录时使用）
#
# 用法：
#   1. 把下面 PUBLIC_KEY 换成你自己的公钥（本机 ~/.ssh/id_rsa.pub 的内容）
#   2. scp 到服务器后执行：bash install-public-key.sh
#   3. 本脚本不会修改 sshd 的 PermitRootLogin；如需 root 密钥登录请自行确认配置
set -e

PUBLIC_KEY='ssh-rsa AAAA...请替换为你的公钥...'

if [ "$PUBLIC_KEY" = 'ssh-rsa AAAA...请替换为你的公钥...' ]; then
  echo "请先在脚本中填入你的公钥（PUBLIC_KEY）" >&2
  exit 1
fi

mkdir -p /root/.ssh
chmod 700 /root/.ssh

grep -qxF "$PUBLIC_KEY" /root/.ssh/authorized_keys 2>/dev/null || echo "$PUBLIC_KEY" >> /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys

# CentOS 7 若开启 SELinux，修正上下文
restorecon -Rv /root/.ssh 2>/dev/null || true

# 确认 sshd 允许密钥登录
grep -E '^(PermitRootLogin|PubkeyAuthentication)' /etc/ssh/sshd_config || true

echo "done"

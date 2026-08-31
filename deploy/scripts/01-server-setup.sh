#!/bin/bash
set -e

echo "Starting server setup and security hardening..."

# Set noninteractive frontend for apt to avoid dialogs
export DEBIAN_FRONTEND=noninteractive
export TERM=xterm

# Generate locales and set environment variables
locale-gen en_US.UTF-8 uk_UA.UTF-8
update-locale LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8

export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8

# Reconfigure locales (optional)
dpkg-reconfigure --frontend=noninteractive locales

# Update and upgrade
apt-get update && apt-get upgrade -y

# Install sudo if missing
apt-get install -y sudo

# Install needed packages
apt-get install -y rsync curl dnsutils cron ufw fail2ban unzip iproute2 yq

SUDOERS_FILE="/etc/sudoers.d/deploy-temp"

# Create deploy user if not exists
if ! id -u deploy >/dev/null 2>&1; then
    # Create user without password
    adduser --gecos "" --disabled-password deploy
    # Add to sudo group
    usermod -aG sudo deploy
    echo "Created deploy user"
else
    echo "Deploy user already exists"
fi

# Temporarily allow deploy to run specific commands via sudo without password
sudo tee "$SUDOERS_FILE" > /dev/null <<EOL
Defaults:deploy !requiretty
deploy ALL=(ALL) NOPASSWD: /usr/bin/apt-get, /usr/bin/curl, /usr/bin/install, /usr/bin/chmod, /usr/bin/chown, /usr/bin/tee, /usr/sbin/usermod, /bin/systemctl, /usr/bin/docker, /usr/bin/mkdir, /usr/bin/cp, /usr/bin/mv, /usr/bin/rm, /usr/sbin/logrotate, /usr/bin/tar, /usr/bin/rsync
EOL

# Validate sudoers syntax
sudo visudo -cf $SUDOERS_FILE || { echo "Sudoers syntax error"; exit 1; }

# Create .ssh directory and authorized_keys file for deploy user
echo "Setting up SSH access for deploy user..."
mkdir -p /home/deploy/.ssh
touch /home/deploy/.ssh/authorized_keys

# CRITICAL: Copy SSH key from root to deploy user
# This assumes the SSH key was already added to root during server provisioning
if [ -f /root/.ssh/authorized_keys ]; then
    echo "Copying SSH keys from root to deploy user..."
    cat /root/.ssh/authorized_keys >> /home/deploy/.ssh/authorized_keys
    echo "SSH keys copied successfully"
else
    echo "WARNING: No SSH keys found in /root/.ssh/authorized_keys"
    echo "You may need to manually add your SSH key to /home/deploy/.ssh/authorized_keys"
fi

# Remove duplicates from authorized_keys if any
sort /home/deploy/.ssh/authorized_keys | uniq > /tmp/authorized_keys_tmp
mv /tmp/authorized_keys_tmp /home/deploy/.ssh/authorized_keys

# Set ownership and permissions for .ssh directory and authorized_keys
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys

echo "SSH setup completed. Contents of /home/deploy/.ssh/authorized_keys:"
wc -l /home/deploy/.ssh/authorized_keys
head -c 100 /home/deploy/.ssh/authorized_keys
echo "..."

# # Configure sudo for deploy user without password for specific commands
# echo "Configuring passwordless sudo for deploy user..."
# cat > /etc/sudoers.d/deploy <<EOL
# # Allow deploy user to run specific commands without password
# deploy ALL=(ALL) NOPASSWD: /bin/systemctl restart *, /bin/systemctl start *, /bin/systemctl stop *, /bin/systemctl status *, /bin/systemctl reload *
# deploy ALL=(ALL) NOPASSWD: /usr/sbin/ufw allow *, /usr/sbin/ufw delete *, /usr/sbin/ufw status
# deploy ALL=(ALL) NOPASSWD: /bin/chown, /bin/chmod
# deploy ALL=(ALL) NOPASSWD: /usr/bin/rsync
# # For other commands, require password
# deploy ALL=(ALL:ALL) ALL
# EOL

# Disable IPv6 in UFW config
sudo sed -i 's/^IPV6=yes/IPV6=no/' /etc/default/ufw

# Reload UFW to apply IPv6 setting
sudo ufw disable

# Allow HTTP, HTTPS, and SSH ports
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 443/udp
sudo ufw allow 22/tcp

# Enable UFW firewall forcefully
sudo ufw --force enable

# Show UFW status
sudo ufw status verbose

# Configure fail2ban with default SSH port (will be updated later)
cat > /etc/fail2ban/jail.local <<EOL
[sshd]
enabled = true
port = 22
filter = sshd
logpath = /var/log/auth.log
maxretry = 5
bantime = 3600
findtime = 600
EOL

# Restart fail2ban to apply settings
systemctl restart fail2ban

# Create application directories
mkdir -p /var/www/html
chown deploy:deploy /var/www/html
chmod 755 /var/www/html
mkdir -p /var/seaweedfs/data
chown deploy:deploy /var/seaweedfs/data
chmod 775 /var/seaweedfs/data
mkdir -p /home/deploy/backups
chown deploy:deploy /home/deploy/backups
chmod 700 /home/deploy/backups

# Set timezone and disable unnecessary services
timedatectl set-timezone UTC
systemctl disable --now apt-daily.timer
systemctl disable --now apt-daily-upgrade.timer
apt-get autoremove -y
apt-get autoclean

# Test SSH key access for deploy user
echo "Testing SSH key access for deploy user..."
su - deploy -c "ssh-keygen -l -f ~/.ssh/authorized_keys" || echo "SSH key validation failed or no keys present"

echo "Server setup completed successfully!"
echo "Deploy user created with SSH key access"
echo "Make sure your SSH key is properly configured for the deploy user"
#!/bin/bash
# PLACEHOLDERS — will be replaced by Envoy
SSH_PORT_OLD="***SSH_PORT***"
SSH_PORT_NEW="***SSH_PORT_NEW***"

# Validate inputs
if [[ -z "$SSH_PORT_NEW" || -z "$SSH_PORT_OLD" ]]; then
    echo "Error: SSH_PORT_OLD or SSH_PORT_NEW is not set"
    exit 1
fi

if ! [[ "$SSH_PORT_NEW" =~ ^[0-9]+$ ]]; then
    echo "Error: SSH_PORT_NEW must be numeric"
    exit 1
fi

# Check if port is already in use
if ss -tuln | grep -E "0\.0\.0\.0:$SSH_PORT_NEW|:$SSH_PORT_NEW\b" >/dev/null; then
    echo "Error: Port $SSH_PORT_NEW is already in use"
    exit 1
fi

# Function to cleanup on failure
cleanup_on_failure() {
    echo "Cleaning up after failure..."
    sudo rm -rf /etc/systemd/system/ssh.socket.d/
    sudo systemctl daemon-reload
    sudo systemctl restart ssh
}

# Handle UFW firewall BEFORE making changes
if command -v ufw >/dev/null 2>&1 && ufw status | grep -q "Status: active"; then
    echo "Allowing port $SSH_PORT_NEW in UFW..."
    sudo ufw allow "$SSH_PORT_NEW"/tcp
    sudo ufw reload
fi

# Handle firewalld
if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
    echo "Allowing port $SSH_PORT_NEW in firewalld..."
    sudo firewall-cmd --permanent --add-port="$SSH_PORT_NEW"/tcp
    sudo firewall-cmd --reload
fi

# Handle SELinux if present
if command -v sestatus >/dev/null 2>&1 && sestatus | grep -q "SELinux status:.*enabled"; then
    echo "Configuring SELinux for SSH port $SSH_PORT_NEW..."
    if command -v semanage >/dev/null 2>&1; then
        sudo semanage port -a -t ssh_port_t -p tcp "$SSH_PORT_NEW" 2>/dev/null || \
        sudo semanage port -m -t ssh_port_t -p tcp "$SSH_PORT_NEW"
    else
        echo "Warning: SELinux is enabled but semanage not found. This might cause issues."
    fi
fi

# Step 1: Create socket override directory
echo "Creating systemd socket override directory..."
sudo mkdir -p /etc/systemd/system/ssh.socket.d

# Step 2: Create socket override configuration with explicit IPv4/IPv6
echo "Configuring SSH socket to listen on both ports with explicit IPv4/IPv6..."
cat << EOF | sudo tee /etc/systemd/system/ssh.socket.d/listen.conf > /dev/null
[Socket]
ListenStream=
ListenStream=0.0.0.0:$SSH_PORT_OLD
ListenStream=[::]:$SSH_PORT_OLD
ListenStream=0.0.0.0:$SSH_PORT_NEW
ListenStream=[::]:$SSH_PORT_NEW
EOF

echo "Socket configuration created:"
cat /etc/systemd/system/ssh.socket.d/listen.conf

# Step 3: Reload systemd and try graceful restart first
echo "Reloading systemd configuration..."
sudo systemctl daemon-reload

echo "Attempting to restart SSH socket..."
if sudo systemctl restart ssh.socket && sudo systemctl restart ssh.service; then
    # Wait a moment for services to start
    sleep 2
    
    # Check if both ports are listening
    if ss -tuln | grep -q ":$SSH_PORT_NEW\b"; then
        echo "✅ SSH socket restart successful! Both ports are active."
        
        # Show current listening ports
        echo "Current SSH listening ports:"
        ss -tuln | grep -E "0\.0\.0\.0:$SSH_PORT_OLD|:$SSH_PORT_OLD\b|0\.0\.0\.0:$SSH_PORT_NEW|:$SSH_PORT_NEW\b"
        
        # Test connection to new port
        if timeout 5 bash -c "</dev/tcp/127.0.0.1/$SSH_PORT_NEW" 2>/dev/null; then
            echo "✔ Port $SSH_PORT_NEW is accepting connections"
        else
            echo "⚠ Port $SSH_PORT_NEW is listening but may have firewall issues"
        fi
        
        # Remove old port from socket config (final cleanup)
        echo "Removing old port $SSH_PORT_OLD from socket configuration..."
        cat << EOF | sudo tee /etc/systemd/system/ssh.socket.d/listen.conf > /dev/null
[Socket]
ListenStream=
ListenStream=0.0.0.0:$SSH_PORT_NEW
ListenStream=[::]:$SSH_PORT_NEW
EOF
        
        sudo systemctl daemon-reload
        sudo systemctl restart ssh.socket
        sudo systemctl restart ssh.service
        
        sleep 2
        
        # Final verification
        if ss -tuln | grep -q "0\.0\.0\.0:$SSH_PORT_NEW\b"; then
            echo "✅ SSH port successfully changed to $SSH_PORT_NEW using socket activation"
            echo "Final SSH listening port:"
            ss -tuln | grep "0\.0\.0\.0:$SSH_PORT_NEW\b"
        else
            echo "❌ Final cleanup failed. Reverting..."
            cleanup_on_failure
            exit 1
        fi
        
        exit 0
    fi
fi

# If graceful restart failed, try the nuclear option
echo "⚠ Graceful restart failed. Using reinstall method..."

# Step 4: Remove and reinstall openssh-server (nuclear option)
echo "Removing openssh-server..."
sudo apt remove --purge -y openssh-server

echo "Reinstalling openssh-server..."
if ! sudo apt install -y openssh-server ssh; then
    echo "❌ Failed to reinstall openssh-server"
    cleanup_on_failure
    exit 1
fi

# Step 5: Reload systemd and restart services
echo "Reloading systemd daemon..."
sudo systemctl daemon-reload

echo "Restarting SSH services..."
sudo systemctl restart ssh.socket
sudo systemctl restart ssh.service

# Wait for services to start
sleep 3

# Verification
echo "Verifying SSH is listening on new port..."
for i in {1..10}; do
    if ss -tuln | grep -q "0\.0\.0\.0:$SSH_PORT_NEW\b"; then
        echo "✅ SSH is listening on port $SSH_PORT_NEW"
        
        # Show service status
        echo "SSH service status:"
        sudo systemctl status ssh --no-pager -l | head -10
        
        # Show listening ports
        echo "SSH listening ports:"
        ss -tuln | grep -E "0\.0\.0\.0:$SSH_PORT_OLD|:$SSH_PORT_OLD\b|0\.0\.0\.0:$SSH_PORT_NEW|:$SSH_PORT_NEW\b"
        
        # Test connection
        if timeout 5 bash -c "</dev/tcp/127.0.0.1/$SSH_PORT_NEW" 2>/dev/null; then
            echo "✔ Port $SSH_PORT_NEW is accepting connections"
        else
            echo "⚠ Port $SSH_PORT_NEW is listening but may have connectivity issues"
        fi
        
        # Final cleanup - remove old port
        echo "Cleaning up: removing old port $SSH_PORT_OLD..."
        cat << EOF | sudo tee /etc/systemd/system/ssh.socket.d/listen.conf > /dev/null
[Socket]
ListenStream=
ListenStream=0.0.0.0:$SSH_PORT_NEW
ListenStream=[::]:$SSH_PORT_NEW
EOF
        
        sudo systemctl daemon-reload
        sudo systemctl restart ssh.socket
        sudo systemctl restart ssh.service
        
        sleep 2
        echo "✅ SSH port change completed successfully using socket activation!"
        echo "You can now connect using: ssh -p $SSH_PORT_NEW user@host"
        exit 0
    fi
    
    echo "Waiting for SSH to start... ($i/10)"
    sleep 1
done

# If we get here, something went wrong
echo "❌ SSH failed to start on port $SSH_PORT_NEW after reinstall"
echo "SSH service status:"
sudo systemctl status ssh --no-pager -l
echo "SSH socket status:"
sudo systemctl status ssh.socket --no-pager -l

cleanup_on_failure
exit 1
echo "Host: $(hostname)"
echo "  - Network Interface IP: $(ip route get 1.1.1.1 | awk '{print $7}' | head -n 1)"
echo "  - Host IP as exposed to lando: $(ip route | grep docker | awk '{print $9}')"
LANDO_HOST_IP=$(lando ssh -c 'env' |grep LANDO_HOST_IP | awk -F '=' '{print $2}');
LANDO_APP_PROJECT=$(lando ssh -c 'env' |grep LANDO_APP_PROJECT | awk -F '=' '{print $2}');
LANDO_APP_NAME=$(lando ssh -c 'env' |grep LANDO_APP_NAME | awk -F '=' '{print $2}');
echo "Lando app name: ${LANDO_APP_NAME}"
echo "  - Lando app project: $LANDO_APP_PROJECT"
echo "  - Lando host IP: $LANDO_HOST_IP"
echo 
echo "XDebug:"
xdebug_client_host=$(lando php -i | grep  'xdebug.client_host' | cut -d ' ' -f 3);
xdebug_client_port=$(lando php -i | grep  'xdebug.client_port' | cut -d ' ' -f 3);
xdebug_mode=$(lando php -i | grep  'xdebug.mode' | cut -d ' ' -f 3);
echo "  - xdebug.client_host: $xdebug_client_host"
echo "  - xdebug.client_port: $xdebug_client_port"
echo "  - xdebug.mode: $xdebug_mode"

echo

if [ -z "$xdebug_client_host" ] || [ -z "$xdebug_client_port" ]; then
  echo "XDebug client host or port is not set. Cannot test connection."
else
  echo "Testing connection to port $xdebug_client_port on $xdebug_client_host"
  lando ssh -c "nc -zv -w 5 $xdebug_client_host $xdebug_client_port && echo 'Port is open' || echo 'Port is closed'"
fi

# Check if xmlstarlet is installed
if ! command -v xmlstarlet &> /dev/null; then
  echo "xmlstarlet is not installed."
  echo "You can usually install it using one of the following commands:"
  echo "  sudo apt-get install xmlstarlet"
  echo "  sudo yum install xmlstarlet"
  echo "  sudo brew install xmlstarlet"
  echo "  sudo pacman -S xmlstarlet"
  exit 1
fi

# Path to the XML file
FILE_PATH=".idea/workspace.xml"

# Check if the file exists
if [ ! -f "$FILE_PATH" ]; then
  echo "File $FILE_PATH does not exist."
  exit 1
fi

# Process the XML
echo "PhpDebugGeneral Component:"
xmlstarlet sel -t \
  -m "//component[@name='PhpDebugGeneral']" \
  -o "  xdebug_debug_port: " -v "@xdebug_debug_port" -n \
  -o "  break_at_first_line: " -v "@break_at_first_line" -n \
  $FILE_PATH

echo "xdebug_debug_ports:"
xmlstarlet sel -t \
  -m "//xdebug_debug_ports" \
  -o "  port: " -v "@port" -n \
  $FILE_PATH



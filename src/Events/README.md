## Drivers

### Uv
```bash
apt-add-repository ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y php-dev libuv1-dev
git clone https://github.com/amphp/php-uv.git
cd php-uv
phpize
./configure
make
make install
cd ../
rm -R php-uv

echo "extension=uv.so" | sudo tee /etc/php/8.3/cli/conf.d/20-uv.ini
```

### Ev
```bash
apt-add-repository ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y php-dev libev-dev
sudo pecl install ev

echo "extension=ev.so" | sudo tee /etc/php/8.3/cli/conf.d/20-ev.ini
```

### Event
> Enable sockets support in Event [yes] : **no** !!! _(event.so: undefined symbol: socket_ce)_
```bash
apt-add-repository ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y php-dev libevent-dev
sudo pecl install event

echo "extension=event.so" | sudo tee /etc/php/8.3/cli/conf.d/20-event.ini
```

## Timer

> **PHP Warning:**  Swow is incompatible with Swoole because both of Swow and Swoole provide the similar functionality through different implementations.
### Swoole
```bash
apt-add-repository ppa:ondrej/php
sudo apt-get update
sudo apt install php-swoole
```

### Swow
```bash
git clone https://github.com/swow/swow.git
cd swow/ext
phpize
./configure
make
sudo make install
cd ../../
rm -R swow

echo "extension=swow.so" | sudo tee /etc/php/8.3/cli/conf.d/20-swow.ini
```

# Quark Docker image
This folder contains the files used to build and test the `ghcr.io/bedrock-phanatics/quark` Docker image.

Docker is an easy, safe way to run software in a container where it can't affect anything else on your machine.
You don't need to build any dependencies, and updating is as simple as changing the version number of the image you're using.

## Pre-requisites
To install Docker, refer to the [official Docker docs](https://docs.docker.com/engine/install/).

## Running Quark from Docker (using GitHub Container Registry)
This is really easy once you have `docker` installed.

```
mkdir wherever-you-want
cd wherever-you-want
mkdir data plugins
sudo chown -R 1000:1000 data plugins
docker run -it -p 19132:19132/udp -v $PWD/data:/data -v $PWD/plugins:/plugins ghcr.io/bedrock-phanatics/quark
```

To run a specific version, just add it to the end of the command, like this:
```
docker run -it -p 19132:19132/udp -v $PWD/data:/data -v $PWD/plugins:/plugins ghcr.io/bedrock-phanatics/quark:<release-tag>
```

## Network ports

Quark defaults to NetherNet. UDP 19132 serves signaling and Query; gameplay also uses ICE UDP sockets. Publishing only 19132 does not expose those sockets.

For Docker, Pterodactyl, or a server behind NAT, configure an allocated UDP range and the server's reachable public IP in `config/server.toml`:

```toml
[transport.nethernet]
port-range = [30000, 30100]
advertise-addresses = ['203.0.113.7'] # Replace with your public IP.
```

Publish `-p 30000-30100:30000-30100/udp` in addition to `-p 19132:19132/udp`, and forward the same ports on any upstream router or firewall. Pterodactyl must allocate the entire range. ICE ports must have identical internal and external numbers. Without a range, NetherNet may choose arbitrary UDP ports. Keep `nethernet-identity.der` in the persistent data volume so the server identity survives restarts.

STUN discovers NAT mappings; TURN can relay connections when direct access fails. Configure supported server URLs with `transport.nethernet.ice-servers`. Neither service is configured by default. LAN discovery uses UDP 7551 and local broadcasts; ordinary bridged containers usually do not receive those broadcasts. Disable `lan-discovery` when it is unnecessary. UDP multiplexing remains off by default because vanilla compatibility is unverified.

`[network] port` selects the IPv4 signaling/Query or RakNet port. Set `port-v6` for RakNet IPv6 and publish that UDP port too when enabled. For RakNet-only deployments (`[transport] name = 'raknet'`), Docker can translate the external game port with `<external>:19132/udp`; NetherNet ICE ranges must remain mapped unchanged.

## Editing the server data
The server data (e.g. `config/server.toml`, `data/worlds/`, and `data/plugins/`) will be stored in the host `data` folder you created above.

**Note: If you add new files (e.g. a world), don't forget to change the ownership of the file/folder to `1000:1000`**:
```
sudo chown -R 1000:1000 <file/folder you added>
```
This is needed to make the server able to access the file/folder.

## Adding plugins
Plugins can be added by putting them in `plugins` folder you created earlier.


**Note: If you add new files, don't forget to change the ownership of the file/folder to `1000:1000`**:
```
sudo chown -R 1000:1000 <file/folder you added>
```
This is needed to make the server able to access the file/folder.

## Run the server in the background
To run the server in the background, simply change `-it` to `-itd` in the last command above.
This will run the server in the background even if you closed console. (No need to `screen`/`tmux` anymore!)

### Opening the console of the server
Use `docker ps` to see a list of running containers. It will look like this:
```
user@DYLANS-PC:~/quark-docker-test$ docker ps
CONTAINER ID   IMAGE                                     COMMAND                  CREATED         STATUS         PORTS                                                                              NAMES
dc20edd3dd62   ghcr.io/bedrock-phanatics/quark:<release-tag>   "start-quark"       7 seconds ago   Up 6 seconds   19132/tcp, 0.0.0.0:19132-19133->19132-19133/udp, :::19132-19133->19132-19133/udp   brave_dijkstra
```
In this case, the container name is `brave_dijkstra`, but it might be something else in your case.

To open the console, run the following command:

```
docker attach <container name you saw in docker ps>
```

To leave the console, just press `Ctrl p` `Ctrl q`.

### Viewing the logs
To see the logs, run the following command:
```
docker logs --tail=100 <container name you saw in docker ps>
```
Change `--tail=100` to the number of recent lines in the log you want to see.

## Volumes
- `/data` is a read-write data directory where Quark stores all data in.
	This includes `config/`, `data/players/`, `data/worlds/`, `data/plugins/`, `data/access.sqlite`, `resource-packs/`, `logs/`, `diagnostics/`, `backups/`, and `runtime/`.
- `/plugins` is a read-only data directory where Quark loads plugins from.

## Advanced usage: Passing args to Quark.phar inside the container
The `QUARK_ARGS` environment variable will be passed to `Quark.phar` when run.

## Building this image
The Dockerfile requires a build-arg `GIT_HASH` to fill the git hash metadata when building `Quark.phar`.
This ensures that `/version`, crash reports, logs etc. report the correct server version.

Initialize the pinned PHP compiler before building:

```bash
git submodule update --init build/php
docker build --build-arg GIT_HASH="$(git rev-parse HEAD)" -f docker/Dockerfile -t local-quark .
bash docker/test.sh local-quark
```

Use a published release tag in place of `<release-tag>`. The smoke test checks startup, API 6.0.0 plugin loading, and data persistence across separate containers.

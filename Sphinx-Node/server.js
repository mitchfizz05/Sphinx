/*
 * Sphinx server file.
 */

var fs = require("fs");
var spawn = require("child_process").spawn;
var sanitizefs = require("sanitize-filename");
var McProperties = require("./mcproperties.js");

var regexPatterns = {
	started: /\[[0-9]{1,2}:[0-9]{1,2}:[0-9]{1,2}\] \[Server thread\/INFO\]: Done \([0-9]*\.[0-9]*s\)! For help, type "help" or "\?"/g,
};

var serverCommands = {
	stop: "stop", // command to stop the server
	whitelist_add: "whitelist add {PLAYER}", // command to whitelist a player
	whitelist_remove: "whitelist remove {PLAYER}",
	op_add: "op {PLAYER}", // op a player
	op_remove: "deop {PLAYER}", // deop a player
}

function fileExists(file) {
	var exists = false;
	try {
		if (fs.statSync(file)) {
			exists = true;
		}
	} catch (e) {  }
	
	return exists;
}

var Server = function (serverdata) {
	this.serverdata = serverdata;
	
	this.serverPath = "servers/" + parseInt(serverdata.id);
	
	this.running = false; // is the server currently running?
	this.started = false; // has the server finished starting up?
}

/**
 * Provison a Minecraft server.
 * Creates it's directory, sets config and automatically agrees to the EULA.
 */
Server.prototype.provision = function (server) {
	fs.mkdirSync(this.serverPath); // make server directory
	
	// Agree to EULA
	fs.writeFileSync(this.serverPath + "/eula.txt", "eula=true");
	
	// Create server.properties
	this.updateServerProperties();
	
	// Write provision time to file.
	fs.writeFileSync(this.serverPath + "/provisioned.txt", new Date().toString());
}

/**
* Initialize a server's files, ready to launch.
*/
Server.prototype.init = function (server) {
	// Verify the server has the neccesary jar file available.
	if (!fileExists("jars/" + sanitizefs(this.serverdata.jar))) {
		console.log(("Server " + this.serverdata.id + " is missing it's neccesary jar: " + this.serverdata.jar + "!").red);
		return;
	}
	
	// Verify the server has been provisioned.
	if (!fileExists(this.serverPath)) {
		// Server not yet provisioned!
		console.log("Server " + this.serverdata.id + " does not have a directory. Generating one now...");
		this.provision();
	}
	
	console.log(("Server " + this.serverdata.id + " good to go!").green);
}

/**
 * Start the Minecraft server.
 */
Server.prototype.start = function () {
	var _this = this;
	
	console.log(("Starting server " + this.serverdata.id + "...").yellow);
	
	var jarfile = __dirname + "/jars/" + this.serverdata.jar;
	
	// Spawn Java process.
	this.process = spawn("java", ["-jar", jarfile, "-Xmx512M", "nogui"], {
		cwd: __dirname + "\\" + this.serverPath
	});
	
	this.running = true;
	
	this.process.stdout.setEncoding("utf-8");
	this.process.stdin.setEncoding("utf-8");
	
	var handleData = function (data) {
		// Received output from server!
		var str = data.toString();
		var lines = str.split("\n");
		
		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];
			
			//console.log(line); // uncomment for server output
			
			if (regexPatterns.started.test(line)) {
				// Server has started.
				_this.started = true;
				console.log(("Server " + _this.serverdata.id + " has started.").yellow);
			}
		}
	}
	this.process.stdout.on("data", handleData);
	this.process.stderr.on("data", handleData);
	this.process.on("close", function (code) {
		// Server exited.
		_this.started = false;
		_this.running = false;
		console.log(("Server " + _this.serverdata.id + " has stopped. Code: " + code).yellow);
	})
}

/**
 * Send a command to the server.
 */
Server.prototype.sendCommand = function (command) {
	this.process.stdin.write(command + "\n");
}

/**
 * Stop the Minecraft server.
 */
Server.prototype.stop = function () {
	this.sendCommand("stop");
}

/**
 * Check if the server is running.
 */
Server.prototype.isRunning = function () {
	return this.running;
}

/**
 * Update the server properties
 */
Server.prototype.updateServerProperties = function () {
	var _this = this;
	
	// Open server properties file for changes.
	if (fileExists(this.serverPath + "/server.properties")) {
		var props = new McProperties(fs.readFileSync(this.serverPath + "/server.properties", "utf-8"));
	} else {
		// Properties don't exist. Creating blank one...
		var props = new McProperties("");
	}
	
	// Modify values.
	Object.keys(this.serverdata.properties).forEach(function (key) {
		var value = _this.serverdata.properties[key];
		
		props.set(key, value);
	});
	
	// Save
	fs.writeFileSync(this.serverPath + "/server.properties", props.compile());
}

/**
 * Update server whitelist/ops file.
 */
Server.prototype.updateServerLists = function (mode) {
	if (mode == "ops") {
		var currentlist = this.serverdata.ops;
		var listfile = this.serverPath + "/ops.json";
	} else if (mode == "whitelist") {
		var currentlist = this.serverdata.whitelist;
		var listfile = this.serverPath + "/whitelist.json";
	} else {
		this.updateServerLists("whitelist");
		
		mode = "ops";
		var currentlist = this.serverdata.ops;
		var listfile = this.serverPath + "/ops.json";
	}
	
	if (this.running) {
		// Server is running. Read list from file, compare difference, and op/whitelist via console.
		// This won't be fully up-to-date, but duplicate /whitelist and /op commands don't harm anything (much).
		
		var disklist = JSON.parse(fs.readFileSync(listfile, "utf-8"));
		
		// Flatten disk list.
		var newdisklist = [];
		for (var i = 0; i < disklist.length; i++) {
			newdisklist.push(disklist[i].name);
		}
		
		// Flatten current list.
		var newcurrentlist = [];
		for (var i = 0; i < currentlist.length; i++) {
			newcurrentlist.push(currentlist[i].name);
		}
		
		var toRemove = newdisklist.filter(function (player) {
			return newcurrentlist.indexOf(player) < 0;
		});
		
		var toAdd = newcurrentlist.filter(function (player) {
			return newdisklist.indexOf(player) < 0;
		});
		
		// Select appropiate command for mode.
		if (mode == "whitelist") {
			var addCommand = serverCommands.whitelist_add;
			var removeCommand = serverCommands.whitelist_remove;
		} else {
			var addCommand = serverCommands.op_add;
			var removeCommand = serverCommands.op_remove;
		}
			
		// Remove players.
		for (var i = 0; i < toRemove.length; i++) {
			this.sendCommand(removeCommand.replace("{PLAYER}", toRemove[i]));
		}
		
		// Add players.
		for (var i = 0; i < toAdd.length; i++) {
			this.sendCommand(addCommand.replace("{PLAYER}", toAdd[i]));
		}
	} else {
		if (mode == "ops") {
			// Add op level to JSON structure.
			for (var i = 0; i < currentlist.length; i++) {
				currentlist[i]["level"] = 4; // op level 4
			}
		}
		
		// Server not running, simply overwrite the file.
		fs.writeFileSync(listfile, JSON.stringify(currentlist));
	}
}

module.exports = Server;
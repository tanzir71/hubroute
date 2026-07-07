import { spawn } from "node:child_process";

function quotePowerShell(value) {
  return `'${String(value).replaceAll("'", "''")}'`;
}

function run(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      stdio: "inherit",
      ...options
    });
    child.on("error", reject);
    child.on("close", (code) => {
      if (code === 0) {
        resolve();
        return;
      }
      reject(new Error(`${command} ${args.join(" ")} exited with ${code}`));
    });
  });
}

export function createArchiveCommand({ cwd, zipPath, hasZip, platform = process.platform }) {
  if (hasZip || platform !== "win32") {
    return {
      command: "zip",
      args: ["-qr", zipPath, "."],
      options: { cwd }
    };
  }

  return {
    command: "powershell",
    args: [
      "-NoProfile",
      "-Command",
      `Compress-Archive -Path * -DestinationPath ${quotePowerShell(zipPath)} -Force`
    ],
    options: { cwd }
  };
}

export function commandExists(command) {
  return new Promise((resolve) => {
    const child = spawn(command, ["--version"], { stdio: "ignore" });
    child.on("error", () => resolve(false));
    child.on("close", (code) => resolve(code === 0));
  });
}

export async function archiveDirectory({ cwd, zipPath }) {
  const hasZip = await commandExists("zip");
  const archiveCommand = createArchiveCommand({ cwd, zipPath, hasZip });
  await run(archiveCommand.command, archiveCommand.args, archiveCommand.options);
}

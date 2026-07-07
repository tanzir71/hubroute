import test from "node:test";
import assert from "node:assert/strict";
import { createArchiveCommand } from "../scripts/package-archive.mjs";

test("uses powershell archive fallback on Windows when zip is unavailable", () => {
  const command = createArchiveCommand({
    cwd: "C:\\repo\\dist\\hubroute-php-sqlite",
    zipPath: "C:\\repo\\dist\\hubroute-php-sqlite.zip",
    hasZip: false,
    platform: "win32"
  });

  assert.equal(command.command, "powershell");
  assert.deepEqual(command.args, [
    "-NoProfile",
    "-Command",
    "Compress-Archive -Path * -DestinationPath 'C:\\repo\\dist\\hubroute-php-sqlite.zip' -Force"
  ]);
  assert.equal(command.options.cwd, "C:\\repo\\dist\\hubroute-php-sqlite");
});

test("uses zip when the binary is available", () => {
  const command = createArchiveCommand({
    cwd: "/repo/dist/hubroute-vercel",
    zipPath: "/repo/dist/hubroute-vercel.zip",
    hasZip: true,
    platform: "linux"
  });

  assert.equal(command.command, "zip");
  assert.deepEqual(command.args, ["-qr", "/repo/dist/hubroute-vercel.zip", "."]);
  assert.equal(command.options.cwd, "/repo/dist/hubroute-vercel");
});

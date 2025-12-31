Import("env")
import os

# Load .env file
env_file = os.path.join(env.get("PROJECT_DIR"), ".env")
if os.path.exists(env_file):
    with open(env_file) as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                key, value = line.split("=", 1)
                # Add as build flag with proper escaping for strings
                env.Append(CPPDEFINES=[
                    (key, env.StringifyMacro(value))
                ])
else:
    print("WARNING: .env file not found! Copy .env.example to .env")

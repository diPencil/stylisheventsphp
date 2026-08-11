import { PHASE_DEVELOPMENT_SERVER } from "next/constants.js"
import path from "node:path"
import { fileURLToPath } from "node:url"

const frontendDir = path.dirname(fileURLToPath(import.meta.url))

/** @type {(phase: string) => import('next').NextConfig} */
const nextConfig = (phase) => ({
  ...(phase === PHASE_DEVELOPMENT_SERVER
    ? { distDir: ".next-dev2" }
    : {}),
  output: "standalone",
  skipTrailingSlashRedirect: true,
  images: {
    unoptimized: true,
  },
  trailingSlash: true,
  webpack: (config) => {
    config.resolve = config.resolve || {}
    config.resolve.alias = {
      ...(config.resolve.alias || {}),
      "@": frontendDir,
    }
    return config
  },
  async rewrites() {
    const apiBaseUrl = process.env.NEXT_PUBLIC_API_BASE_URL
    if (!apiBaseUrl || !/^https?:\/\//i.test(apiBaseUrl)) return []
    return [
      {
        source: "/api/:path*",
        destination: `${apiBaseUrl.replace(/\/$/, "")}/api/:path*`,
      },
    ]
  },
})

export default nextConfig

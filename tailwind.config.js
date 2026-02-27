/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./*.php",
    "./includes/landing-*.php",
    "./assets/js/landing.js",
    "./teacher/**/*.php"
  ],
  theme: {
    container: {
      center: true,
      padding: {
        DEFAULT: "1.25rem",
        md: "2rem",
        xl: "2.75rem"
      },
      screens: {
        xl: "1200px",
        "2xl": "1280px"
      }
    },
    extend: {
      colors: {
        ink: {
          950: "#0b1622",
          900: "#0f1f2d",
          800: "#162a3b",
          700: "#20384e"
        },
        slate: {
          700: "#334155",
          600: "#475569",
          500: "#64748b",
          400: "#94a3b8"
        },
        mist: {
          100: "#eef3f7",
          50: "#f7fafc"
        },
        teal: {
          700: "#0f6a5c",
          600: "#168575",
          500: "#1fa38f"
        },
        amber: {
          500: "#f3b648",
          400: "#f8c766"
        }
      },
      fontFamily: {
        sans: ["Manrope", "Segoe UI", "sans-serif"],
        display: ["Fraunces", "Georgia", "serif"]
      },
      boxShadow: {
        soft: "0 10px 24px rgba(15, 31, 51, 0.08)",
        lift: "0 18px 40px rgba(15, 31, 51, 0.12)",
        glow: "0 12px 24px rgba(22, 133, 117, 0.22)"
      },
      borderRadius: {
        xl2: "1.25rem",
        xl3: "1.75rem"
      },
      backgroundImage: {
        "hero-glow": "radial-gradient(circle at top, rgba(31, 163, 143, 0.18), transparent 60%)"
      }
    }
  },
  plugins: []
};

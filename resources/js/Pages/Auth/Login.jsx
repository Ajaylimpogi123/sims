import { Head, Link, useForm } from "@inertiajs/react";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

// Decorative graduation-cap shape used across the background layer.
// Purely visual — aria-hidden, no interaction.
function GradCap({ className, color, style }) {
    return (
        <svg
            viewBox="0 0 100 70"
            className={className}
            style={style}
            aria-hidden="true"
        >
            <polygon
                points="50,5 95,25 50,45 5,25"
                fill={color}
                opacity="0.9"
            />
            <rect
                x="35"
                y="25"
                width="30"
                height="12"
                fill={color}
                opacity="0.7"
            />
            <circle cx="50" cy="25" r="3.5" fill="rgba(255,255,255,0.7)" />
            <line
                x1="50"
                y1="26"
                x2="72"
                y2="54"
                stroke={color}
                strokeWidth="2"
            />
            <circle cx="72" cy="57" r="4" fill={color} />
        </svg>
    );
}

function OpenBook({ className, color, style }) {
    return (
        <svg
            viewBox="0 0 100 70"
            className={className}
            style={style}
            aria-hidden="true"
        >
            <path
                d="M50 15 C40 8 20 8 10 15 L10 55 C20 48 40 48 50 55 C60 48 80 48 90 55 L90 15 C80 8 60 8 50 15 Z"
                fill={color}
                opacity="0.85"
            />
            <line
                x1="50"
                y1="15"
                x2="50"
                y2="55"
                stroke="rgba(255,255,255,0.35)"
                strokeWidth="1.5"
            />
        </svg>
    );
}

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: "",
        password: "",
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("login"), {
            onFinish: () => reset("password"),
        });
    };

    return (
        <div className="bcc-login relative min-h-screen flex items-center justify-center overflow-hidden bg-[#0F1712] px-4">
            <Head title="Sign in" />

            {/* Ambient glow */}
            <div className="bcc-orb bcc-orb-a" aria-hidden="true" />
            <div className="bcc-orb bcc-orb-b" aria-hidden="true" />
            <div className="bcc-grain" aria-hidden="true" />

            {/* Academic-themed decorative field */}
            <div
                className="absolute inset-0 pointer-events-none"
                aria-hidden="true"
            >
                <GradCap
                    color="#1F7A3D"
                    className="bcc-cap absolute w-24 opacity-70 blur-[1px]"
                    style={{
                        top: "8%",
                        left: "6%",
                        animationDelay: "0s",
                    }}
                />
                <GradCap
                    color="#F5B301"
                    className="bcc-cap absolute w-20 opacity-55 blur-[2px]"
                    style={{
                        top: "62%",
                        left: "-1%",
                        animationDelay: "-4s",
                    }}
                />
                <GradCap
                    color="#145C34"
                    className="bcc-cap absolute w-24 opacity-60 blur-[2px]"
                    style={{
                        top: "4%",
                        right: "4%",
                        animationDelay: "-8s",
                    }}
                />
                <OpenBook
                    color="#F5B301"
                    className="bcc-book absolute w-24 opacity-60 blur-[1px]"
                    style={{
                        bottom: "6%",
                        right: "3%",
                        animationDelay: "-2s",
                    }}
                />
                <OpenBook
                    color="#1F7A3D"
                    className="bcc-book absolute w-20 opacity-45 blur-[2px]"
                    style={{
                        bottom: "18%",
                        left: "16%",
                        animationDelay: "-6s",
                    }}
                />
                <GradCap
                    color="#FFD666"
                    className="bcc-cap absolute w-14 opacity-45 blur-[1px]"
                    style={{ top: "22%", left: "30%", animationDelay: "-1s" }}
                />
                <OpenBook
                    color="#1F7A3D"
                    className="bcc-book absolute w-12 opacity-40 blur-[2px]"
                    style={{ bottom: "28%", left: "6%", animationDelay: "-5s" }}
                />
                <GradCap
                    color="#F5B301"
                    className="bcc-cap absolute w-14 opacity-45 blur-[1px]"
                    style={{ top: "14%", right: "24%", animationDelay: "-3s" }}
                />
                <OpenBook
                    color="#145C34"
                    className="bcc-book absolute w-12 opacity-35 blur-[2px]"
                    style={{
                        bottom: "9%",
                        right: "20%",
                        animationDelay: "-7s",
                    }}
                />
            </div>

            <div className="relative z-10 w-full max-w-[400px]">
                {/* Brand */}
                <div className="flex flex-col items-center mb-8">
                    <div className="flex items-center justify-center w-32 h-32 rounded-2xl bg-white/[0.06] border border-white/10 shadow-[0_8px_30px_-12px_rgba(31,122,61,0.35)] backdrop-blur-xl mb-4">
                        <img
                            src="/images/logo/bcc-logo.jpg"
                            alt="Bacolod City College"
                            className="w-full h-full object-contain rounded-lg p-3"
                        />
                    </div>
                    <span className="bcc-display text-[#F1F2F6] text-lg tracking-tight text-center">
                        Bacolod City College
                    </span>
                    <span className="text-xs text-[#9AA69E] mt-0.5">
                        Student Internship Monitoring System
                    </span>
                </div>

                {/* Glass card */}
                <div className="bcc-card rounded-3xl border border-white/10 bg-white/[0.05] backdrop-blur-2xl shadow-[0_24px_70px_-20px_rgba(0,0,0,0.5)] px-7 py-8">
                    <h1 className="bcc-display text-[#F1F2F6] text-2xl tracking-tight mb-1 text-center">
                        Welcome back
                    </h1>
                    <p className="text-sm text-[#9AA69E] mb-7 text-center">
                        Sign in to continue
                    </p>

                    {status && (
                        <div className="mb-5 rounded-xl border border-emerald-400/15 bg-emerald-400/[0.07] px-3.5 py-2.5 text-sm text-emerald-300">
                            {status}
                        </div>
                    )}

                    <form onSubmit={submit} noValidate className="space-y-4">
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="email"
                                className="text-xs font-medium text-[#A9B3AB]"
                            >
                                Email
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                placeholder="you@bcc.edu.ph"
                                autoComplete="username"
                                autoFocus
                                onChange={(e) =>
                                    setData("email", e.target.value)
                                }
                                className={`h-11 rounded-xl border-white/10 bg-white/[0.04] text-[#F1F2F6] placeholder:text-[#5F6B62] focus-visible:ring-2 focus-visible:ring-[#F5B301]/35 focus-visible:border-[#F5B301]/50 ${
                                    errors.email
                                        ? "border-red-400/40 focus-visible:ring-red-400/20"
                                        : ""
                                }`}
                            />
                            {errors.email && (
                                <p className="text-xs text-red-400 font-medium">
                                    {errors.email}
                                </p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <div className="flex items-center justify-between">
                                <Label
                                    htmlFor="password"
                                    className="text-xs font-medium text-[#A9B3AB]"
                                >
                                    Password
                                </Label>
                            </div>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                placeholder="••••••••"
                                autoComplete="current-password"
                                onChange={(e) =>
                                    setData("password", e.target.value)
                                }
                                className={`h-11 rounded-xl border-white/10 bg-white/[0.04] text-[#F1F2F6] placeholder:text-[#5F6B62] focus-visible:ring-2 focus-visible:ring-[#F5B301]/35 focus-visible:border-[#F5B301]/50 ${
                                    errors.password
                                        ? "border-red-400/40 focus-visible:ring-red-400/20"
                                        : ""
                                }`}
                            />
                            {errors.password && (
                                <p className="text-xs text-red-400 font-medium">
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <div className="flex items-center gap-2 pt-1">
                            <Checkbox
                                id="remember"
                                name="remember"
                                checked={data.remember}
                                onCheckedChange={(checked) =>
                                    setData("remember", checked)
                                }
                                className="border-white/15 data-[state=checked]:bg-[#1F7A3D] data-[state=checked]:border-[#1F7A3D]"
                            />
                            <Label
                                htmlFor="remember"
                                className="text-sm font-normal text-[#9AA69E] cursor-pointer"
                            >
                                Keep me signed in
                            </Label>
                        </div>

                        <Button
                            type="submit"
                            disabled={processing}
                            className="bcc-cta w-full h-11 rounded-xl text-white font-medium border-0 mt-2"
                        >
                            {processing ? (
                                <span className="flex items-center gap-2">
                                    <svg
                                        className="animate-spin h-4 w-4"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <circle
                                            className="opacity-25"
                                            cx="12"
                                            cy="12"
                                            r="10"
                                            stroke="currentColor"
                                            strokeWidth="4"
                                        />
                                        <path
                                            className="opacity-75"
                                            fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                        />
                                    </svg>
                                    Signing in…
                                </span>
                            ) : (
                                "Sign in"
                            )}
                        </Button>
                    </form>

                    <p className="text-center text-sm text-[#9AA69E] mt-6">
                        Don't have an account?{" "}
                        <Link
                            href={route("register")}
                            className="text-[#F5B301] font-medium hover:text-[#FFD666] transition-colors"
                        >
                            Register here
                        </Link>
                    </p>
                </div>

                <p className="text-center text-xs text-[#5F6B62] mt-6">
                    Bacolod City College
                </p>
            </div>

            <style>{`
                .bcc-display {
                    font-family: 'Space Grotesk', 'Inter', sans-serif;
                    font-weight: 500;
                }

                .bcc-orb {
                    position: absolute;
                    border-radius: 9999px;
                    filter: blur(110px);
                    opacity: 0.22;
                    pointer-events: none;
                }
                .bcc-orb-a {
                    width: 560px;
                    height: 560px;
                    top: -200px;
                    left: -160px;
                    background: radial-gradient(circle, #1F7A3D 0%, transparent 70%);
                }
                .bcc-orb-b {
                    width: 480px;
                    height: 480px;
                    bottom: -220px;
                    right: -140px;
                    background: radial-gradient(circle, #F5B301 0%, transparent 70%);
                    opacity: 0.16;
                }

                .bcc-grain {
                    position: absolute;
                    inset: 0;
                    opacity: 0.035;
                    pointer-events: none;
                    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
                }

                .bcc-cap {
                    animation: bcc-drift 14s ease-in-out infinite;
                    filter: drop-shadow(0 10px 20px rgba(0,0,0,0.25));
                }
                .bcc-book {
                    animation: bcc-drift-fast 10s ease-in-out infinite;
                    filter: drop-shadow(0 10px 20px rgba(0,0,0,0.25));
                }
                @keyframes bcc-drift {
                    0%, 100% { transform: translate(0, 0) rotate(0deg); }
                    50%      { transform: translate(10px, -10px) rotate(4deg); }
                }
                @keyframes bcc-drift-fast {
                    0%, 100% { transform: translate(0, 0); }
                    50%      { transform: translate(-8px, 8px); }
                }

                .bcc-card {
                    animation: bcc-card-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
                }
                @keyframes bcc-card-in {
                    from { opacity: 0; transform: translateY(10px); }
                    to   { opacity: 1; transform: translateY(0); }
                }

                .bcc-cta {
                    background: linear-gradient(135deg, #1F7A3D 0%, #F5B301 100%);
                    transition: filter 0.2s ease, transform 0.15s ease;
                }
                .bcc-cta:hover {
                    filter: brightness(1.08);
                }
                .bcc-cta:active {
                    transform: scale(0.98);
                }

                @media (prefers-reduced-motion: reduce) {
                    .bcc-cap, .bcc-book, .bcc-card { animation: none; }
                }
            `}</style>
        </div>
    );
}

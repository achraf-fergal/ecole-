"use client";

interface PasswordStrengthProps {
  password: string;
}

export function calculatePasswordStrength(password: string): {
  score: number;
  label: string;
  color: string;
  requirements: { label: string; met: boolean }[];
} {
  const requirements = [
    { label: "At least 12 characters", met: password.length >= 12 },
    { label: "Contains lowercase letter", met: /[a-z]/.test(password) },
    { label: "Contains uppercase letter", met: /[A-Z]/.test(password) },
    { label: "Contains a number", met: /[0-9]/.test(password) },
    { label: "Contains a special symbol", met: /[^A-Za-z0-9]/.test(password) },
  ];

  const metCount = requirements.filter((r) => r.met).length;

  let score = 0;
  let label = "Very Weak";
  let color = "bg-red-500";

  if (metCount >= 5 && password.length >= 12) {
    score = 4;
    label = "Strong";
    color = "bg-emerald-500";
  } else if (metCount >= 4) {
    score = 3;
    label = "Good";
    color = "bg-teal-500";
  } else if (metCount >= 3) {
    score = 2;
    label = "Fair";
    color = "bg-amber-500";
  } else if (metCount >= 2) {
    score = 1;
    label = "Weak";
    color = "bg-orange-500";
  }

  return { score, label, color, requirements };
}

export default function PasswordStrengthIndicator({ password }: PasswordStrengthProps) {
  if (!password) {
    return null;
  }

  const { score, label, color, requirements } = calculatePasswordStrength(password);

  return (
    <div className="mt-2 space-y-2" aria-live="polite">
      <div className="flex items-center justify-between text-xs">
        <span className="font-semibold text-slate-500">Password strength:</span>
        <span className="font-bold text-slate-700">{label}</span>
      </div>

      <div className="grid grid-cols-4 gap-1.5" aria-hidden="true">
        {[1, 2, 3, 4].map((step) => (
          <div
            key={step}
            className={`h-1.5 rounded-full transition-colors duration-200 ${
              score >= step ? color : "bg-slate-200"
            }`}
          />
        ))}
      </div>

      <ul className="mt-2 grid grid-cols-1 gap-1 text-xs text-slate-500 sm:grid-cols-2">
        {requirements.map((req) => (
          <li key={req.label} className="flex items-center gap-1.5">
            <span
              className={`inline-block h-1.5 w-1.5 rounded-full ${
                req.met ? "bg-emerald-500" : "bg-slate-300"
              }`}
            />
            <span className={req.met ? "text-slate-700" : "text-slate-400"}>
              {req.label}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

import { Tabs, TabsContent, TabsList, TabsTrigger } from "./components/ui/tabs";
import logoSvg from "./assets/logo.svg";
import { Input } from "./components/ui/input";
import { Label } from "./components/ui/label";
import { Button } from "./components/ui/button";
import { useEffect, useState } from "react";
import { toast } from "./components/ui/use-toast";

interface LeadTrackrForm {
  id: string;
  title?: string;
  sendToLeadTrackr?: boolean;
  customTitle?: string;
}

declare global {
  interface Window {
    wpData: {
      apiUrl: string;
      projectId: string;
      gravityForms: {
        enabled: boolean;
        forms: LeadTrackrForm[];
      };
      cf7: {
        enabled: boolean;
        forms: LeadTrackrForm[];
      };
      elementor: {
        enabled: boolean;
        forms: LeadTrackrForm[];
      };
      wpforms: {
        enabled: boolean;
        forms: LeadTrackrForm[];
      }
      fluentForms: {
        enabled: boolean;
        forms: LeadTrackrForm[];
      }
    };
  }
}

function App() {
  const [activeTab, setActiveTab] = useState<
    "general" | "gravity-forms" | "contact-form-7" | "elementor" | "wpforms" | "fluent-forms" | string
  >("general");
  const [projectId, setProjectId] = useState<string>("");
  const [loading, setLoading] = useState<boolean>(false);
  const [forms, setForms] = useState<LeadTrackrForm[]>([]);

  useEffect(() => {
    if (typeof window.wpData !== "undefined") {
      if (window.wpData.projectId) {
        setProjectId(window.wpData.projectId);
      }
    } else {
      console.error("wpData is not defined");
    }
  }, []);

  useEffect(() => {
    if (activeTab === "gravity-forms") {
      setForms(window.wpData.gravityForms.forms);
    }

    if (activeTab === "contact-form-7") {
      setForms(window.wpData.cf7.forms);
    }

    if (activeTab === "elementor") {
      setForms(window.wpData.elementor.forms);
    }

    if (activeTab === "wpforms") {
      setForms(window.wpData.wpforms.forms);
    }

    if (activeTab === "fluent-forms") {
      setForms(window.wpData.fluentForms.forms);
    }
  }, [activeTab]);

  const onSaveProjectId = async () => {
    setLoading(true);
    const response = await fetch(`${window.wpData.apiUrl}/project-id`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      credentials: "include",
      body: JSON.stringify({ project_id: projectId }),
    });

    if (response.ok) {
      toast({
        title: "Project ID saved",
        description: "Project ID has been saved successfully",
        variant: "default",
      });
    } else {
      toast({
        title: "Failed to save project ID",
        description: "Something went wrong",
        variant: "destructive",
      });
    }

    setLoading(false);
  };

  const onSaveForms = async () => {
    setLoading(true);
    const response = await fetch(`${window.wpData.apiUrl}/${activeTab}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      credentials: "include",
      body: JSON.stringify({
        forms: structuredClone(forms).map((f) => {
          delete f.title;

          return f;
        }),
      }),
    });

    if (response.ok) {
      toast({
        title: "Forms saved",
        description: "The forms have been saved successfully",
        variant: "default",
      });
    } else {
      toast({
        title: "Failed to save forms",
        description: "Something went wrong",
        variant: "destructive",
      });
    }

    setLoading(false);
  };

  const formsContent = (
    <>
      <div className="flex items-center gap-12 border-b py-2">
        <Label className="w-1/6">ID</Label>
        <Label className="w-1/6">Form title</Label>
        <Label className="w-1/6">Custom form name</Label>
        <Label className="w-1/6">Send to LeadTrackr</Label>
      </div>
      {forms.length > 0 ? (
        forms.map((form) => (
          <div
            key={`${activeTab}-${form.id}`}
            className="flex items-center gap-12 border-b py-2"
          >
            <Label className="w-1/6">{form.id}</Label>
            <Label className="font-light w-1/6">{form.title}</Label>
            <Input
              id={`custom-name-${form.id}`}
              type="text"
              className="w-1/6"
              value={form.customTitle}
              onChange={(event) => {
                setForms(
                  forms.map((f) =>
                    f.id === form.id
                      ? { ...f, customTitle: event.target.value }
                      : f
                  )
                );
              }}
            />
            <Input
              id={`send-to-leadtrackr-${form.id}`}
              type="checkbox"
              className="w-1/6"
              checked={form.sendToLeadTrackr}
              onChange={(event) => {
                setForms(
                  forms.map((f) =>
                    f.id === form.id
                      ? { ...f, sendToLeadTrackr: event.target.checked }
                      : f
                  )
                );
              }}
            />
          </div>
        ))
      ) : (
        <p>No GravityForms found</p>
      )}
    </>
  );

  return (
    <div style={{ width: "90%", margin: "auto" }} className="sm:py-8">
      <img
        src={logoSvg}
        className="h-7 text-3xl font-semibold tracking-tight mb-4"
        alt="LeadTrackr"
      />
      <Tabs
        value={activeTab}
        onValueChange={(tab) => {
          setActiveTab(tab);
        }}
      >
        <TabsList>
          <TabsTrigger value="general">Project general</TabsTrigger>
          <TabsTrigger
            disabled={!window.wpData.gravityForms.enabled}
            value="gravity-forms"
          >
            GravityForms
          </TabsTrigger>
          <TabsTrigger
            disabled={!window.wpData.cf7.enabled}
            value="contact-form-7"
          >
            Contact Form 7
          </TabsTrigger>
          <TabsTrigger
            disabled={!window.wpData.elementor.enabled}
            value="elementor"
          >
            Elementor
          </TabsTrigger>
          <TabsTrigger
            disabled={!window.wpData.wpforms.enabled}
            value="wpforms"
          >
            WPForms
          </TabsTrigger>
          <TabsTrigger
            disabled={!window.wpData.fluentForms.enabled}
            value="fluent-forms"
          >
            Fluent Forms
          </TabsTrigger>
        </TabsList>

        <div className="mt-2">
          <TabsContent value="general">
            <div className="w-2/6">
              <Label className="block mb-2" htmlFor="project-id">
                Project ID
              </Label>
              <Input
                value={projectId}
                onChange={(event) => setProjectId(event.target.value)}
                name="project-id"
                id="project-id"
              />
            </div>
            <Button
              onClick={() => {
                onSaveProjectId();
              }}
              disabled={loading}
              className="mt-2"
            >
              {loading ? "Saving..." : "Save"}
            </Button>
          </TabsContent>
          <TabsContent value="gravity-forms">{formsContent}</TabsContent>
          <TabsContent value="contact-form-7">{formsContent}</TabsContent>
          <TabsContent value="elementor">{formsContent}</TabsContent>
          <TabsContent value="wpforms">{formsContent}</TabsContent>
          <TabsContent value="fluent-forms">{formsContent}</TabsContent>
          {activeTab !== "general" && (
            <Button disabled={loading} onClick={onSaveForms} className="mt-2">
              {loading ? "Saving..." : "Save"}
            </Button>
          )}
        </div>
      </Tabs>
    </div>
  );
}

export default App;

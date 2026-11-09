#include "my_application.h"

#include <sys/resource.h>

int main(int argc, char** argv) {
  // Base locale : un fichier + un verrou par type de données ; on relève la limite de fichiers ouverts.
  struct rlimit rl;
  if (getrlimit(RLIMIT_NOFILE, &rl) == 0 && rl.rlim_cur < 10240) {
    rl.rlim_cur = rl.rlim_max < 10240 ? rl.rlim_max : 10240;
    setrlimit(RLIMIT_NOFILE, &rl);
  }
  g_autoptr(MyApplication) app = my_application_new();
  return g_application_run(G_APPLICATION(app), argc, argv);
}
